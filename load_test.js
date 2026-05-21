import http from 'k6/http';
import { sleep, check, group } from 'k6';

// ─────────────────────────────────────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────────────────────────────────────

const BASE_URL = 'http://localhost/4th_year_project/public/api';

// Credentials of a real user in your DB
// This user must have email_verified_at set (not null)
const TEST_EMAIL    = 'alzebiabdalrahman@gmail.com';
const TEST_PASSWORD = 'arayaz8152002';

// A refresh token you got from a manual login in Postman
// Used for testing the refresh endpoint under load
// Get it from: POST /api/auth/login → tokens.refresh_token
const REFRESH_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOlwvXC9sb2NhbGhvc3Q6ODAwMCIsInN1YiI6MzcsImlhdCI6MTc3OTExMzY4OSwiZXhwIjoxNzc5MTQ5Njg5LCJqdGkiOiI4YTAxY2QyYy0yNWEyLTQzNGYtYjQ0My05ZWFmM2Q5MDBiNmIiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.FLhilBy9OiNUh1_TttYq4BOlNtFaA_RDVN7f0cnOt2k';

// A reset token from a manual forgot-password flow in Postman
// Get it from: POST /api/auth/password/forgot → then POST /api/auth/password/verify-otp → reset_token
const RESET_TOKEN = 'PASTE_RESET_TOKEN_HERE';

// ─────────────────────────────────────────────────────────────────────────────
// LOAD PROFILE
// Start small — auth routes are expensive (bcrypt password hashing)
// bcrypt by design is slow (~100-200ms per hash) so don't expect sub-50ms
// ─────────────────────────────────────────────────────────────────────────────

export const options = {
    stages: [
        { duration: '30s', target: 5  },  // warm up — auth is CPU heavy
        { duration: '1m',  target: 20 },  // normal load
        { duration: '30s', target: 50 },  // moderate stress
        { duration: '1m',  target: 50 },  // hold
        { duration: '30s', target: 0  },  // cool down
    ],
    thresholds: {
        // Auth routes are slower than data routes because of bcrypt
        // 3s is a realistic target for login under load
        'http_req_duration{group:::Login}':          ['p(95)<3000'],
        'http_req_duration{group:::Refresh Token}':  ['p(95)<500'],
        'http_req_duration{group:::Forgot Password}':['p(95)<1000'],
        http_req_failed: ['rate<0.05'],
    },
};

function publicHeaders() {
    return {
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
        },
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────────────────────────────────────

export default function () {

    // ── LOGIN ─────────────────────────────────────────────────────────────────
    // Tests: credential lookup + bcrypt verify + JWT generation
    // Expected: 200 with tokens, or 401 if credentials wrong
    // Why slow: bcrypt is intentionally slow (cost factor ~10 = ~100-200ms per hash)
    group('Login', function () {
        const res = http.post(
            `${BASE_URL}/auth/login`,
            JSON.stringify({
                email:    TEST_EMAIL,
                password: TEST_PASSWORD,
            }),
            publicHeaders()
        );

        const success = check(res, {
            'login: status 200':        r => r.status === 200,
            'login: has access_token':  r => {
                try {
                    return JSON.parse(r.body).tokens?.access_token !== undefined;
                } catch { return false; }
            },
            'login: response under 3s': r => r.timings.duration < 3000,
        });

        if (!success) {
            console.log(`login failed: ${res.status} — ${res.body.substring(0, 150)}`);
        }

        sleep(1);
    });

    // ── REFRESH TOKEN ─────────────────────────────────────────────────────────
    // Tests: refresh token lookup + new JWT generation
    // Much faster than login — no bcrypt involved
    // Note: using a static refresh token here — in reality each user has their own
    // For a real test you would login first and use that refresh token
    group('Refresh Token', function () {
        const res = http.post(
            `${BASE_URL}/auth/refresh`,
            JSON.stringify({
                refresh_token: REFRESH_TOKEN,
            }),
            publicHeaders()
        );

        check(res, {
            'refresh: status 200 or 401': r => [200, 401].includes(r.status),
            'refresh: response under 500ms': r => r.timings.duration < 500,
        });

        // 401 here is expected after first use since refresh tokens are single-use
        // This test mainly measures the endpoint's response time

        sleep(0.5);
    });

    // ── FORGOT PASSWORD ───────────────────────────────────────────────────────
    // Tests: user lookup by email + OTP generation + email queue
    // The email is queued, not sent synchronously, so this should be fast
    // We use a non-existent email so no real email is sent
    group('Forgot Password', function () {
        const res = http.post(
            `${BASE_URL}/auth/password/forgot`,
            JSON.stringify({
                email: 'nonexistent_load_test@example.com',
            }),
            publicHeaders()
        );

        check(res, {
            // 404 = user not found, which is fine — we just want to measure response time
            'forgot password: status 200 or 404': r => [200, 404].includes(r.status),
            'forgot password: response under 1s':  r => r.timings.duration < 1000,
        });

        sleep(0.5);
    });

    // ── VERIFY PASSWORD OTP ───────────────────────────────────────────────────
    // Tests: OTP lookup + validation logic
    // Using a fake OTP — will return 400 "invalid code" but measures route speed
    group('Verify Password OTP', function () {
        const res = http.post(
            `${BASE_URL}/auth/password/verify-otp`,
            JSON.stringify({
                email:    TEST_EMAIL,
                otp_code: '000000',  // deliberately wrong — measures speed not correctness
            }),
            publicHeaders()
        );

        check(res, {
            // 400 = invalid OTP, expected — we just want the response time
            'verify otp: status 400 or 200': r => [200, 400, 422].includes(r.status),
            'verify otp: response under 1s':  r => r.timings.duration < 1000,
        });

        sleep(0.5);
    });

    // ── RESET PASSWORD ────────────────────────────────────────────────────────
    // Tests: reset token cache lookup + password hash + token revocation
    // Using expired/fake token — will return 400 but measures route speed
    group('Reset Password', function () {
        const res = http.post(
            `${BASE_URL}/auth/password/reset`,
            JSON.stringify({
                reset_token:           '00000000-0000-0000-0000-000000000000',
                password:              'NewPassword123',
                password_confirmation: 'NewPassword123',
            }),
            publicHeaders()
        );

        check(res, {
            // 400 = expired/invalid token, expected
            'reset password: status 400 or 200': r => [200, 400, 422].includes(r.status),
            'reset password: response under 2s':  r => r.timings.duration < 2000,
        });

        sleep(0.5);
    });

    sleep(1);
}
