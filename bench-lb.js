import http from 'k6/http';
import { check } from 'k6';

export const options = { vus: 50, duration: '30s' };

export function setup() {
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
