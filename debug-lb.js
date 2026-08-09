import http from 'k6/http';

export default function () {
    const res = http.post(
        'http://localhost:8080/api/admin/login',
        JSON.stringify({ email: 'primary@admin.com', password: 'admin' }),
        { headers: { 'Content-Type': 'application/json' } }
    );
    console.log('STATUS:', res.status);
    console.log('BODY:', res.body);
}
