import http from 'k6/http';

export default function () {
    const loginRes = http.post('http://localhost:8001/api/staff/login',
        JSON.stringify({ email: 'YOUR_STAFF_EMAIL', password: 'YOUR_STAFF_PASSWORD' }),
        { headers: { 'Content-Type': 'application/json' } }
    );
    console.log('STATUS:', loginRes.status);
    console.log('BODY:', loginRes.body);
}
