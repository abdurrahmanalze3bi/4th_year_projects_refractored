/**
 * syride-perf-test.js
 * Run via run-perf-test.ps1 -- TOKEN injected automatically as k6 env var.
 * Do not run directly.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';

const TOKEN = __ENV.TOKEN;
if (!TOKEN) throw new Error('TOKEN env var missing -- run via run-perf-test.ps1');

const BASE    = 'http://localhost:8080';
const HEADERS = {
    Authorization: 'Bearer ' + TOKEN,
    Accept:        'application/json',
};

export const options = {
    stages: [
        { duration: '30s', target: 100 },  // warm up
        { duration: '2m',  target: 470 },  // sustained load -- your known operating point
        { duration: '30s', target: 750 },  // spike -- stress the difference
        { duration: '30s', target: 0   },  // ramp down
    ],

    // Thresholds: Stage A (no indexes, 1M rows) will likely breach these.
    // B and C should pass. That is the point of the test.
    thresholds: {
        http_req_failed:                             ['rate<0.05'],
        'http_req_duration{name:rides}':             ['p(95)<3000'],
        'http_req_duration{name:transactions}':      ['p(95)<2000'],
    },
};

export default function () {
    // Randomise page so each VU hits a different cache key.
    // Without this, Redis masks the index benefit after the first few requests.
    var page = Math.ceil(Math.random() * 100);

    // Rides listing -- drives idx_rides_status_departure_seats
    // At 1M rows without the index, MySQL scans ~3M rows per request.
    var rides = http.get(
        BASE + '/api/rides?status=active&page=' + page,
        { headers: HEADERS, tags: { name: 'rides' } }
    );
    check(rides, { 'rides 2xx': function(r) { return r.status >= 200 && r.status < 300; } });

    // Wallet transactions -- drives idx_bookings_user_status
    var txns = http.get(
        BASE + '/api/wallet/transactions?page=' + page,
        { headers: HEADERS, tags: { name: 'transactions' } }
    );
    check(txns, { 'txns 2xx': function(r) { return r.status >= 200 && r.status < 300; } });

    sleep(1);
}
