import http from 'k6/http';
import { check } from 'k6';

// SCENARIO 1 — No Cache, No Load Balancer
// Before running:
//   docker compose stop app2 app3
//   set CACHE_DRIVER: array in docker-compose.yml for app1
//   docker compose up -d --force-recreate app1
//   docker exec syride_app1 php artisan cache:clear

export const options = { vus: 50, duration: '30s' };

export function setup() {
    console.log('SCENARIO 1 — No Cache | No Load Balancer');
    const res = http.post(
        'http://localhost:8080/api/admin/login',
        JSON.stringify({ email: 'primary@admin.com', password: 'admin' }),
        { headers: { 'Content-Type': 'application/json' } }
    );
    return { token: res.json('tokens.access_token') };
}

export default function (data) {
    const res = http.get('http://localhost:8080/api/admin/dashboard/stats', {
        headers: { Authorization: `Bearer ${data.token}` },
    });
    check(res, { 'status 200': (r) => r.status === 200 });
}
