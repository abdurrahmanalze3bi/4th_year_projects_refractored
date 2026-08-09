import http from 'k6/http';
import { check } from 'k6';

// SCENARIO 4 — Cache + Load Balancer (BEST CASE)
// Before running:
//   set CACHE_DRIVER: redis in docker-compose.yml for app1, app2, app3
//   docker compose up -d --force-recreate app1 app2 app3
//   docker exec syride_app1 php artisan cache:clear

export const options = { vus: 50, duration: '30s' };

export function setup() {
    console.log('SCENARIO 4 — Redis Cache | Load Balancer (3 nodes)');
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
