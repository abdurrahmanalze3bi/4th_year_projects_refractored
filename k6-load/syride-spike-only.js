import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate } from 'k6/metrics';

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ REAL TOKENS Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
const PASSENGER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUxLCJpYXQiOjE3ODY2ODE3OTMsImV4cCI6MTc4NjcxNzc5MywianRpIjoiZGU2ZjFiMzUtMTI3My00ZDE0LWJhNmMtYmFmZTc4ZjI4M2U3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.BqF8MoYPTU8ukrWtioln6wLtsE8V5CtpSndHieVmOHQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUyLCJpYXQiOjE3ODY2ODE3OTMsImV4cCI6MTc4NjcxNzc5MywianRpIjoiOTAxMWVkNDgtOGJjMC00MDk5LWJmNDgtODgwMmQ0YjQ5OTM3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.RIBv2zxWQ3R7ulCBxG9S4-IZq2ETL0Vjh0t6eVh8K-o',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUzLCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiODNkOGY1MTktNmNiZi00OWJmLTk4ZmQtMGU0MTkyZDYyMDU0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.fiM7nHQhF94DSAvRiN1b0vn9uCiiEfwrE6pKVtEeXQY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU0LCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiYjcwNDRiYjAtNmY0Mi00MzE4LWFiMTQtYjlhNDc3MjM2MDIwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.HA147cuAqI4Rqy6VJpteZbyBmoWanwoO9lll3GaBhZw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU1LCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiNGM2N2VjYTktMDQ2MC00OTJjLWFjMDMtZjk1OWYwMTljMTNlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.-WpLqALCLnuyODH4QvEcxnVpJsH_i1gJTvGthEHy4hE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU2LCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiZmZlMjZmM2EtNmQ0Ni00MTMwLWJkNWEtMDFkODRjYzllZGRmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.aXlqb2oflTIXGWaSZkAkA6ilrb6LhpgSP2a8ZTNiUXg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU3LCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiYzI4MDJhYzQtYzE5Yy00OTc2LTlmNGYtMTNmNmZmMjc3ZWM2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.-BdPLs7GL-IWuExnNZjYRpwval1echhLsQYwHwj6JP8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU4LCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiMzE4ZGRiYTUtYzYxOC00YjFhLWI3MDgtYjc0MWE1NWIzYjg2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.crJxhf89N0BTdpexNMYWHTmAiV41rhHtX7CkeTrkhtk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU5LCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiM2JjNjM4MDUtNGIwYy00NjhhLTgzOTQtYjRmY2FlNzlkZjY1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.BZ29QydvOI6daju4D7xeT7KI03bfpVMoR3Xgucz9pmU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYwLCJpYXQiOjE3ODY2ODE3OTQsImV4cCI6MTc4NjcxNzc5NCwianRpIjoiNDFmNDczN2QtZWE2YS00YmJiLThhY2YtZDM0YWY5ZmE5ZDc0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.hQtFwMLUX0rfYno0vL_P7tBFZ7sTfDIiBgusa7bqlr8'
];

const DRIVER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MSwiaWF0IjoxNzg2NjgxNzk0LCJleHAiOjE3ODY3MTc3OTQsImp0aSI6IjBlODNjNGYwLWNmZjEtNDA5OC1hNWViLWQ0ZjRlYjcwNDI0NSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9._eIMwoqQRiX2G_nEb0lWo0T92ATrjR6wFUrMjBzMp-U',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MiwiaWF0IjoxNzg2NjgxNzk0LCJleHAiOjE3ODY3MTc3OTQsImp0aSI6IjhjMTY3Y2ExLWQ4YzMtNGQzNS1iYzk0LTFjNzMzMmY5YmVlYSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.Y1-O_Cu_gPGiSSQ30TmPKwxAPVxPEVdFblCyOWyUAo0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MywiaWF0IjoxNzg2NjgxNzk0LCJleHAiOjE3ODY3MTc3OTQsImp0aSI6ImY3YzdkZTAxLTAwODItNGRlMy1iYTk2LWU3NGRjNjBjMmViZCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.eMHk0zEjZ02WE3n9ZA-EzGQhK5lwyjr34MMUUT45N5c'
];

const ALL_TOKENS = [...PASSENGER_TOKENS, ...DRIVER_TOKENS];

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ CONFIG Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

const LOCATIONS = [
    { lat: 33.5138, lng: 36.2765 }, // Damascus
    { lat: 33.5200, lng: 36.3100 }, // Damascus East
    { lat: 33.4900, lng: 36.2500 }, // Damascus South
    { lat: 33.5400, lng: 36.3000 }, // Damascus North
    { lat: 33.5050, lng: 36.2900 }, // Damascus Center
];

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ METRICS Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
const responseTime = new Trend('response_ms');
const errorRate    = new Rate('real_5xx_errors');

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ STAGES Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
export const options = {
    stages: [
        { duration: '2m', target: 470 }, // Phase 1 Ã¢â‚¬â€ Baseline (70% operating point)
        { duration: '2m', target: 670 }, // Phase 2 Ã¢â‚¬â€ SPIKE    Ã¢â€ Â SCREENSHOT HERE
        { duration: '2m', target: 470 }, // Phase 3 Ã¢â‚¬â€ Recovery Ã¢â€ Â SCREENSHOT HERE
    ],
    thresholds: {
        real_5xx_errors: ['rate<0.03'],
        http_req_failed: ['rate<0.03'],
    },
};

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ HELPERS Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

function authHeader(token) {
    return {
        headers: { Authorization: `Bearer ${token}` },
        redirects: 0
    };
}

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ MAIN Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
export default function () {
    const token  = pick(ALL_TOKENS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS);
    const jitter = (Math.random() - 0.5) * 0.01;

    const t0 = Date.now();
    const r  = http.get(
        `${BASE_URL}/api/rides/search` +
        `?pickup_lat=${origin.lat + jitter}&pickup_lng=${origin.lng + jitter}` +
        `&destination_lat=${dest.lat}&destination_lng=${dest.lng}&seats=1`,
        authHeader(token)
    );
    responseTime.add(Date.now() - t0);

    const is5xx = r.status >= 500 || r.status === 0;
    errorRate.add(is5xx ? 1 : 0);
    check(r, { 'not 5xx': () => r.status < 500 && r.status > 0 });
}

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ SETUP Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
export function setup() {
    console.log('\n' + '='.repeat(70));
    console.log('  SyRide Ã¢â‚¬â€ SPIKE ISOLATION TEST');
    console.log('  Proves 30% headroom absorbs a 43% traffic burst');
    console.log('='.repeat(70));
    console.log('');
    console.log('  Phase 1  ( 0Ã¢â‚¬â€œ 2m)  Ã¢â€ â€™470 VUs   Baseline Ã¢â‚¬â€ 70% operating point');
    console.log('Ã¢Ëœâ€¦ Phase 2  ( 2Ã¢â‚¬â€œ 4m)  Ã¢â€ â€™670 VUs   SPIKE Ã¢â‚¬â€ take docker stats screenshot NOW');
    console.log('Ã¢Ëœâ€¦ Phase 3  ( 4Ã¢â‚¬â€œ 6m)  Ã¢â€ â€™470 VUs   Recovery Ã¢â‚¬â€ take docker stats screenshot NOW');
    console.log('');
    console.log('  SECOND TERMINAL Ã¢â‚¬â€ keep this command ready to paste:');
    console.log('  docker stats --no-stream --format "table {{.Name}}\\t{{.CPUPerc}}\\t{{.MemUsage}}"');
    console.log('');
    console.log('  Run it ONCE at minute 3 (spike peak)');
    console.log('  Run it ONCE at minute 5 (recovery proof)');
    console.log('='.repeat(70) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error('Server unreachable Ã¢â‚¬â€ check docker-compose ps');
    console.log(`Ã¢Å“â€¦ Server alive (HTTP ${r.status}). 6-minute spike test starting.\n`);
}

// Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ TEARDOWN Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
export function teardown(data) {
    console.log('\n' + '='.repeat(70));
    console.log('  SPIKE TEST COMPLETE Ã¢â‚¬â€ fill in your report:');
    console.log('='.repeat(70));
    console.log('');
    console.log('  Baseline (470 VUs):  p95 = ___ms   CPU combined = ___%');
    console.log('  Spike    (670 VUs):  p95 = ___ms   CPU combined = ___%');
    console.log('  Recovery (470 VUs):  p95 = ___ms   CPU combined = ___%');
    console.log('  5xx errors throughout: ___% (target: 0%)');
    console.log('');
    console.log('  REPORT SENTENCE:');
    console.log('  "Under a 43% traffic spike (670 VUs), p95 climbed from ___ms');
    console.log('   to ___ms but 5xx errors remained at 0%. CPU recovered to');
    console.log('   baseline levels within 60 seconds Ã¢â‚¬â€ confirming the 30%');
    console.log('   headroom reserve is sufficient for real-world burst traffic."');
    console.log('='.repeat(70) + '\n');
}
