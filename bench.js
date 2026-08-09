import http from "k6/http";
import { check } from "k6";

export const options = { vus: 50, duration: "30s" };

export function setup() {
    const res = http.post(
        "http://localhost:8080/api/admin/login",
        JSON.stringify({ email: "primary@admin.com", password: "admin" }),
        { headers: { "Content-Type": "application/json" } }
    );
    return { token: res.json("tokens.access_token") };
}

export default function (data) {
    const headers = { Authorization: `Bearer ${data.token}` };
    // Different limit each time = different cache key = cache miss every request
    const limit = Math.floor(Math.random() * 50) + 1;
    const r1 = http.get(`http://localhost:8080/api/admin/routes/popular?limit=${limit}`, { headers });
    const r2 = http.get(`http://localhost:8080/api/admin/drivers/top?limit=${limit}`, { headers });
    check(r1, { "popular routes 200": (r) => r.status === 200 });
    check(r2, { "top drivers 200": (r) => r.status === 200 });
}
