import http from 'k6/http';
import { sleep, check } from 'k6';

const BASE_URL = 'http://localhost/4th_year_project/public/api';
const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOlwvXC9sb2NhbGhvc3Q6ODAwMCIsInN1YiI6MSwiaWF0IjoxNzc5MDMyNTg5LCJleHAiOjE3NzkwNjg1ODksImp0aSI6IjlhYjVmMDc2LTllYzMtNGRhMC1iZTExLTliYjEzMTViYzAzYiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.WgwV_J4m5mXKSgCJUMo6UQQ5lQJjs71-cqqkOtLft9c';

export const options = {
    stages: [
        { duration: '30s', target: 10  },
        { duration: '1m',  target: 50  },
        { duration: '30s', target: 100 },
        { duration: '1m',  target: 100 },
        { duration: '30s', target: 200 },
        { duration: '30s', target: 0   },
    ],
    thresholds: {
        http_req_duration: ['p(95)<500'],
        http_req_failed:   ['rate<0.01'],
    },
};

const headers = {
    'Authorization': `Bearer ${TOKEN}`,
    'Content-Type':  'application/json',
};

export default function () {
    // Test your most critical routes
    const rides = http.get(`${BASE_URL}/rides`, { headers });
    check(rides, { 'rides status 200': r => r.status === 200 });

    const search = http.post(`${BASE_URL}/rides/search`, JSON.stringify({
        source_lat:      33.51,
        source_lng:      36.29,
        dest_lat:        34.73,
        dest_lng:        36.72,
        departure_date:  '2025-06-01',
        seats_required:  1,
    }), { headers });
    check(search, { 'search status 200': r => r.status === 200 });

    sleep(1);
}
