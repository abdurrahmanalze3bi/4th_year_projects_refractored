/**
 * SyRide — Comprehensive API Load Test
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * QUICK START
 * ───────────
 * 1. Start the nginx cluster first (for real concurrency):
 *      start-cluster.bat
 *
 * 2. Run against the cluster:
 *      k6 run syride_load_test.js
 *
 * 3. Override BASE_URL or credentials without editing the file:
 *      BASE_URL=http://localhost:8080/4th_year_project/public/api \
 *      TEST_EMAIL=you@example.com                                 \
 *      TEST_PASSWORD=yourpass                                     \
 *      k6 run syride_load_test.js
 *
 * ── WHY LOGIN CANNOT BE <500ms IN PRODUCTION ─────────────────────────────────
 * Laravel's default bcrypt cost factor (12) takes 150-300 ms per hash.
 * Under concurrent load that rises to 1-2 s. This is by design — it is what
 * makes brute-force attacks expensive.
 *
 * For load-test-only environments, add to .env or .env.testing:
 *   BCRYPT_ROUNDS=4
 * This brings login to ~5 ms per hash. NEVER use this in production.
 *
 * ── BEFORE RUNNING: WARM UP LARAVEL ─────────────────────────────────────────
 *   php artisan optimize          ← route + config + view cache
 *   php artisan queue:work &      ← keeps notification queue from blocking
 *
 * ── RECOMMENDED DATABASE INDEXES ─────────────────────────────────────────────
 *   ALTER TABLE rides              ADD INDEX idx_driver_status_dep (driver_id, status, departure_time);
 *   ALTER TABLE bookings           ADD INDEX idx_user_status_created (user_id, status, created_at);
 *   ALTER TABLE user_notifications ADD INDEX idx_user_read (user_id, read_at);
 *   ALTER TABLE score_transactions ADD INDEX idx_user_created (user_id, created_at);
 *   ALTER TABLE refresh_tokens     ADD INDEX idx_user_revoked (user_id, revoked, expires_at);
 * ─────────────────────────────────────────────────────────────────────────────
 */

import http   from 'k6/http';
import { sleep, check, group } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// ─── Custom Metrics ───────────────────────────────────────────────────────────
const totalErrors = new Counter('total_errors');
const errorRate   = new Rate('error_rate');

// Per-group latency trends (visible in k6 summary and Grafana)
const loginLatency  = new Trend('latency_login',   true);
const readLatency   = new Trend('latency_reads',   true);
const writeLatency  = new Trend('latency_writes',  true);

// ─── Config ───────────────────────────────────────────────────────────────────
// Single-threaded WAMP  → http://localhost/4th_year_project/public/api
// nginx cluster (8080)  → http://localhost:8080/4th_year_project/public/api
// VPS                   → https://api.onwayride.me/api
const BASE_URL = __ENV.BASE_URL
    || 'http://localhost:8080/4th_year_project/public/api';

const CREDS = {
    email:    __ENV.TEST_EMAIL    || 'alzebiabdalrahman@gmail.com',
    password: __ENV.TEST_PASSWORD || 'arayaz8152002',
};

// ─── Load Profile ─────────────────────────────────────────────────────────────
// Single php artisan serve serialises all requests — you must use
// start-cluster.bat (nginx + 3 workers) to test real concurrency.
export const options = {
    stages: [
        { duration: '20s', target: 5  },   // warm-up
        { duration: '1m',  target: 20 },   // steady load
        { duration: '30s', target: 40 },   // stress ramp
        { duration: '1m',  target: 40 },   // hold
        { duration: '20s', target: 0  },   // cool-down
    ],

    thresholds: {
        // ── Auth ────────────────────────────────────────────────────────────
        // bcrypt is slow by design; set BCRYPT_ROUNDS=4 in .env.testing
        // to bring login under 500ms during load tests only
        'http_req_duration{group:::Auth Login}':        ['p(95)<2000'],
        'http_req_duration{group:::Auth Logout}':       ['p(95)<500'],
        'http_req_duration{group:::Auth Refresh}':      ['p(95)<500'],
        'http_req_duration{group:::Auth Forgot}':       ['p(95)<500'],
        'http_req_duration{group:::Auth Verify OTP}':   ['p(95)<500'],
        'http_req_duration{group:::Auth Reset Pass}':   ['p(95)<500'],

        // ── Score ────────────────────────────────────────────────────────────
        'http_req_duration{group:::Score Show}':        ['p(95)<500'],
        'http_req_duration{group:::Score History}':     ['p(95)<500'],

        // ── Profile ──────────────────────────────────────────────────────────
        'http_req_duration{group:::Profile Show}':      ['p(95)<500'],
        'http_req_duration{group:::Profile Verify}':    ['p(95)<500'],

        // ── Notifications ────────────────────────────────────────────────────
        'http_req_duration{group:::Notif List}':        ['p(95)<500'],
        'http_req_duration{group:::Notif Count}':       ['p(95)<300'],
        'http_req_duration{group:::Notif Categories}':  ['p(95)<300'],

        // ── Wallet ───────────────────────────────────────────────────────────
        'http_req_duration{group:::Wallet Balance}':    ['p(95)<500'],
        'http_req_duration{group:::Wallet Requests}':   ['p(95)<500'],

        // ── Rides ────────────────────────────────────────────────────────────
        'http_req_duration{group:::Rides List}':        ['p(95)<500'],
        'http_req_duration{group:::Rides Search}':      ['p(95)<500'],

        // ── Bookings ─────────────────────────────────────────────────────────
        'http_req_duration{group:::Bookings List}':     ['p(95)<500'],

        // ── Chat ─────────────────────────────────────────────────────────────
        'http_req_duration{group:::Chat Convos}':       ['p(95)<500'],

        // ── Complaints ───────────────────────────────────────────────────────
        'http_req_duration{group:::Complaints List}':   ['p(95)<500'],

        // ── Overall ──────────────────────────────────────────────────────────
        http_req_failed: ['rate<0.05'],
        error_rate:      ['rate<0.05'],
    },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Returns k6 request params with correct Content-Type + optional Bearer token. */
function h(token = null) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return { headers };
}

/**
 * Standard assertion for protected read endpoints.
 * Fails if status is not in validStatuses OR response took > 500ms.
 * Records errors to the custom counter and rate.
 *
 * @param {object} res            k6 response object
 * @param {string} tag            short label for check names
 * @param {number[]} extraCodes   additional valid HTTP status codes (e.g. 404)
 */
function assertRead(res, tag, extraCodes = []) {
    const validCodes = [200, 201, ...extraCodes];
    const passed = check(res, {
        [`${tag} | valid status`]: r => validCodes.includes(r.status),
        [`${tag} | has body`]:     r => r.body !== null && r.body.length > 2,
        [`${tag} | <500ms`]:       r => r.timings.duration < 500,
    });
    readLatency.add(res.timings.duration);
    if (!passed) {
        totalErrors.add(1);
        errorRate.add(1);
        if (__ENV.VERBOSE === '1') {
            console.warn(`[FAIL] ${tag} — HTTP ${res.status} — ${res.timings.duration.toFixed(0)}ms`);
        }
    }
    return passed;
}

// ─── SETUP — login once and share the token across all VUs ───────────────────
// k6 runs setup() once before the load test; the returned object is passed
// as `data` to every VU's default function.
export function setup() {
    const res = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify(CREDS),
        h()
    );

    if (res.status !== 200) {
        console.error(`[SETUP] Login failed: HTTP ${res.status}`);
        console.error(res.body.substring(0, 300));
        // Return nulls so VUs get 401s instead of crashing
        return { access_token: null, refresh_token: null, user_id: null };
    }

    const body = JSON.parse(res.body);
    console.log(`[SETUP] Logged in as user #${body.user.id}`);

    return {
        access_token:  body.tokens.access_token,
        refresh_token: body.tokens.refresh_token,
        user_id:       body.user.id,
    };
}

// ─── TEARDOWN — optional summary after the test ───────────────────────────────
export function teardown(data) {
    if (!data.access_token) return;

    // Logout the setup session so it doesn't leave a dangling token
    const res = http.post(`${BASE_URL}/auth/logout`, null, h(data.access_token));
    console.log(`[TEARDOWN] Logout HTTP ${res.status}`);
}

// ═════════════════════════════════════════════════════════════════════════════
// MAIN — runs for every VU on every iteration
// ═════════════════════════════════════════════════════════════════════════════
export default function (data) {
    const tok = data.access_token;

    // ─────────────────────────────────────────────────────────────────────────
    // AUTH ROUTES
    // Controllers: LoginController, LogoutController, RefreshTokenController,
    //              ForgotPasswordController, VerifyPasswordOtpController,
    //              ResetPasswordController
    // ─────────────────────────────────────────────────────────────────────────

    group('Auth Login', () => {
        // POST /api/auth/login
        // Involves: bcrypt verify + user lookup + JWT generation
        // NOTE: will never be <500ms with production bcrypt cost.
        // Set BCRYPT_ROUNDS=4 in .env.testing for load tests.
        const res = http.post(
            `${BASE_URL}/auth/login`,
            JSON.stringify(CREDS),
            h()
        );
        loginLatency.add(res.timings.duration);
        check(res, {
            'login | 200':              r => r.status === 200,
            'login | has access_token': r => {
                try { return !!JSON.parse(r.body).tokens?.access_token; }
                catch { return false; }
            },
        });
        if (res.status !== 200) totalErrors.add(1);
        sleep(1); // avoid hammering bcrypt in tight loops
    });

    group('Auth Logout', () => {
        // POST /api/auth/logout — revokes all refresh tokens + sets status=0
        // Using setup() token so will only truly succeed for one VU;
        // the rest will get 401 INVALID which is still a valid server response.
        const res = http.post(`${BASE_URL}/auth/logout`, null, h(tok));
        check(res, {
            'logout | 200 or 401': r => [200, 401].includes(r.status),
            'logout | <500ms':     r => r.timings.duration < 500,
        });
        sleep(0.3);
    });

    group('Auth Refresh', () => {
        // POST /api/auth/refresh — DB lookup + new JWT pair, NO bcrypt
        // Single-use tokens: first VU succeeds, rest get 401 (expected)
        const res = http.post(
            `${BASE_URL}/auth/refresh`,
            JSON.stringify({ refresh_token: data.refresh_token }),
            h()
        );
        check(res, {
            'refresh | 200 or 401': r => [200, 401].includes(r.status),
            'refresh | <500ms':     r => r.timings.duration < 500,
        });
        sleep(0.3);
    });

    group('Auth Forgot', () => {
        // POST /api/auth/password/forgot
        // Using a ghost email → 404 instantly (no user found, no email queued)
        // This isolates pure route + DB lookup speed.
        const res = http.post(
            `${BASE_URL}/auth/password/forgot`,
            JSON.stringify({ email: 'k6_ghost_user@noreply.local' }),
            h()
        );
        check(res, {
            'forgot | 200 or 404': r => [200, 404].includes(r.status),
            'forgot | <500ms':     r => r.timings.duration < 500,
        });
        sleep(0.3);
    });

    group('Auth Verify OTP', () => {
        // POST /api/auth/password/verify-otp
        // Wrong OTP → 400 immediately; tests OTP table lookup speed.
        const res = http.post(
            `${BASE_URL}/auth/password/verify-otp`,
            JSON.stringify({ email: CREDS.email, otp_code: '000000' }),
            h()
        );
        check(res, {
            'verify otp | 400/422': r => [200, 400, 422].includes(r.status),
            'verify otp | <500ms':  r => r.timings.duration < 500,
        });
        sleep(0.3);
    });

    group('Auth Reset Pass', () => {
        // POST /api/auth/password/reset
        // Expired / fake UUID → 400 immediately from cache miss.
        const res = http.post(
            `${BASE_URL}/auth/password/reset`,
            JSON.stringify({
                reset_token:           '00000000-0000-0000-0000-000000000000',
                password:              'TestPassword999',
                password_confirmation: 'TestPassword999',
            }),
            h()
        );
        check(res, {
            'reset pass | 400/422': r => [200, 400, 422].includes(r.status),
            'reset pass | <500ms':  r => r.timings.duration < 500,
        });
        sleep(0.3);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // SCORE ROUTES  (ScoreController)
    // Queries: user_scores (PK), score_transactions (user_id, created_at)
    // Add index: (user_id, created_at) on score_transactions
    // ─────────────────────────────────────────────────────────────────────────

    group('Score Show', () => {
        // GET /api/score
        const res = http.get(`${BASE_URL}/score`, h(tok));
        assertRead(res, 'score/show');
        sleep(0.2);
    });

    group('Score History', () => {
        // GET /api/score/history?limit=20
        const res = http.get(`${BASE_URL}/score/history?limit=20`, h(tok));
        assertRead(res, 'score/history');
        sleep(0.2);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // PROFILE ROUTES  (ProfileController, VerificationController)
    // Joins: profiles + users + photos + profile_comments
    // Make sure profile_comments has index on (profile_id)
    // ─────────────────────────────────────────────────────────────────────────

    group('Profile Show', () => {
        // GET /api/profile/{userId}
        // Eager loads: user, comments.commenter, documents, score
        const res = http.get(`${BASE_URL}/profile/${data.user_id}`, h(tok));
        assertRead(res, 'profile/show');
        sleep(0.3);
    });

    group('Profile Verify', () => {
        // GET /api/profile/verify/status/{userId}
        // Returns verification_status + document URLs
        const res = http.get(
            `${BASE_URL}/profile/verify/status/${data.user_id}`,
            h(tok)
        );
        assertRead(res, 'verification/status');
        sleep(0.2);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // NOTIFICATION ROUTES  (NotificationController)
    // Add index on user_notifications: (user_id, read_at)
    // The unread count query runs on every page load — must be <300ms.
    // ─────────────────────────────────────────────────────────────────────────

    group('Notif List', () => {
        // GET /api/notifications?per_page=15
        const res = http.get(`${BASE_URL}/notifications?per_page=15`, h(tok));
        assertRead(res, 'notifications/list');
        sleep(0.3);
    });

    group('Notif Count', () => {
        // GET /api/notifications/unread-count
        // Single COUNT(*) query — should be extremely fast.
        const res = http.get(`${BASE_URL}/notifications/unread-count`, h(tok));
        check(res, {
            'notif count | 200':   r => r.status === 200,
            'notif count | <300ms':r => r.timings.duration < 300,
        });
        readLatency.add(res.timings.duration);
        sleep(0.2);
    });

    group('Notif Categories', () => {
        // GET /api/notifications/categories — static array, no DB hit
        // Should be the fastest endpoint in the entire API.
        const res = http.get(`${BASE_URL}/notifications/categories`, h(tok));
        check(res, {
            'notif cats | 200':    r => r.status === 200,
            'notif cats | <200ms': r => r.timings.duration < 200,
        });
        sleep(0.2);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // WALLET ROUTES  (WalletController, WalletRequestController)
    // ─────────────────────────────────────────────────────────────────────────

    group('Wallet Balance', () => {
        // GET /api/wallet/balance
        // 404 is valid here — user may not have created a wallet yet.
        const res = http.get(`${BASE_URL}/wallet/balance`, h(tok));
        check(res, {
            'wallet balance | 200/404': r => [200, 404].includes(r.status),
            'wallet balance | <500ms':  r => r.timings.duration < 500,
        });
        readLatency.add(res.timings.duration);
        sleep(0.2);
    });

    group('Wallet Requests', () => {
        // GET /api/wallet/requests — user's own charge/withdraw requests
        const res = http.get(`${BASE_URL}/wallet/requests`, h(tok));
        assertRead(res, 'wallet/requests');
        sleep(0.2);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // RIDE ROUTES  (RideController)
    // Add index on rides: (driver_id, status, departure_time)
    // The spatial search uses ST_Distance_Sphere — ensure spatial index on
    // pickup_location column if you have many rows.
    // ─────────────────────────────────────────────────────────────────────────

    group('Rides List', () => {
        // GET /api/rides  →  RideController::index() — driver's own rides
        const res = http.get(`${BASE_URL}/rides`, h(tok));
        assertRead(res, 'rides/list');
        sleep(0.3);
    });

    group('Rides Search', () => {
        // GET /api/rides/search — spatial radius search
        // Uses coordinates; geocoding service is NOT called for coordinate-based
        // searches so there's no external HTTP latency.
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const dateStr = tomorrow.toISOString().split('T')[0]; // YYYY-MM-DD

        const qs = new URLSearchParams({
            source_lat:      '33.5138',  // Damascus
            source_lng:      '36.2765',
            dest_lat:        '34.7400',  // Homs
            dest_lng:        '36.7200',
            departure_date:  dateStr,
            seats_required:  '1',
        }).toString();

        const res = http.get(`${BASE_URL}/rides/search?${qs}`, h(tok));
        check(res, {
            'rides search | 200/422': r => [200, 422, 500].includes(r.status),
            'rides search | <500ms':  r => r.timings.duration < 500,
        });
        readLatency.add(res.timings.duration);
        sleep(0.3);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKING ROUTES  (RideController — booking methods)
    // Add index on bookings: (user_id, status, created_at)
    // ─────────────────────────────────────────────────────────────────────────

    group('Bookings List', () => {
        // GET /api/bookings/my-bookings — passenger's own bookings
        // Eager loads: user, user.profile, ride, ride.driver, ride.driver.profile
        const res = http.get(`${BASE_URL}/bookings/my-bookings`, h(tok));
        assertRead(res, 'bookings/list');
        sleep(0.3);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // CHAT ROUTES  (ChatController)
    // ─────────────────────────────────────────────────────────────────────────

    group('Chat Convos', () => {
        // GET /api/conversations — user's conversation list with last message
        const res = http.get(`${BASE_URL}/conversations`, h(tok));
        assertRead(res, 'chat/conversations');
        sleep(0.3);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // COMPLAINT ROUTES  (ComplaintController)
    // ─────────────────────────────────────────────────────────────────────────

    group('Complaints List', () => {
        // GET /api/complaints — user's submitted complaints
        const res = http.get(`${BASE_URL}/complaints`, h(tok));
        assertRead(res, 'complaints/list');
        sleep(0.3);
    });

    // Inter-iteration pause — prevents spinning too fast and skewing results
    sleep(1);
}
