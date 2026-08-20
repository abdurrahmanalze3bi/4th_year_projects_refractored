/**
 * SyRide — Stage 1 Ceiling Confirmation Test
 * 3 nodes × 8 workers (24 workers total)
 *
 * PURPOSE: Prove that pushing Stage 1 to 900 VUs does NOT increase
 * throughput beyond ~699 req/s. Throughput should plateau at the
 * CPU ceiling while p95 climbs toward ~1,000ms.
 *
 * Expected result:
 *   req/s:  stays ~699 (same as 400 VU run)  ← CPU-bound, not VU-bound
 *   p95:    climbs to ~900–1,100ms            ← longer queue, same workers
 *   errors: 0.00%                             ← server still handles all
 *
 * If this is true: Stage 1 at 900 VUs < Stage 2 at 900 VUs in req/s,
 * proving Stage 2 is genuinely faster (more CPU capacity from 5 nodes).
 *
 * Run:
 *   k6 run "k6-load\syride-stage1-900vu-confirm.js"
 *
 * Monitor (second terminal):
 *   while ($true) {
 *     Write-Host (Get-Date -Format "HH:mm:ss")
 *     docker stats --no-stream --format "table {{.Name}}`t{{.CPUPerc}}`t{{.MemUsage}}" | findstr "app\|mysql"
 *     Write-Host "---"; Start-Sleep 10
 *   }
 */

import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

http.setResponseCallback(http.expectedStatuses(
    { min: 200, max: 299 }, 400, 401, 403, 404, 409, 422
));

const BASE_URL = 'http://localhost:8080';

// ─── PASSENGER TOKENS (users 251–300) ────────────────────────────────────────
const PASSENGER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MSwiaWF0IjoxNzg3MTkzOTE0LCJleHAiOjE3ODcyMjk5MTQsImp0aSI6IjZkODg1MjZkLTEyMzctNDk2Yi1iMmM0LWJjODJkY2Y5MjRmZSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.sjlXXgU1dYXVFW-uwgkkE9vHSe1lBSbiO1jPtJh-4HA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MiwiaWF0IjoxNzg3MTkzOTE0LCJleHAiOjE3ODcyMjk5MTQsImp0aSI6IjdjZjk4YzVjLWE3ZmUtNGEyZC1hYTAzLTAyMmNjNDI3MzBlMCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.NYr299ZgGbESP1fGD0dLxCm8gT4zPpNz4NFVP0Vp56w',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjI2LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiODg2MGQ1MTQtNjliOS00YjRkLTk2MmUtNDZhYzcxNjUyYTJlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.TfYGvDH0Q2sACoj37fRS5e73ivgy0cIHkyCj8d6K_ss',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjI3LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNTczMzBhZjAtNmU3ZC00Mzc1LWExYmMtZjJiOWNiNWE3Yzg4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.gCEqAWHPF-Yl-fYFjBIkL7jhFd96BH-v65iMG8h76jU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjI4LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYjE3MGFkMzQtZTA5Yy00NTg0LTllMjktYzhjMDIxOTFhM2I4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.4e5Olu1CPIeuJqJC08vbhqsSRPnH4TPcJ99rAWImj3c',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjI5LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNjEyMTcyNmUtZjY0ZC00ZjRmLTkyY2QtZGZhNTQ1YjljY2MzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.y6U6K48IH6SkKvyioi0iLGopStdXPyY-dqzplgL-nTM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjMwLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYmE4M2E4MWMtMGRiNy00NWM3LWE4MDgtMWUwZjkwYmI0MDEzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0._mRg7FDWG5wbmrEcmOuGJJcFCaEtMT4sptMynjLzHzo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjMxLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMWY1MzVjODYtOGM0Yy00MDI5LTk2YWUtMTk4OTA2Y2NiODg2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.MU9zcpnSIAy5Y2V4H0zWTmTpQLofzOEmlN9MLfyOJiQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjMyLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNWJhNzkzNDAtODFkNC00NmUzLTg3OGMtMDI4NzYwNTFjZGM5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.CanwK3-oGuyzxsMImzAQQVr3acjUZdQGsP85yrlI3U0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjMzLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNWMxYTk4ZjAtY2E2ZC00YzllLWI5NDYtZTk5MjFhNjM1ZmNhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.HrKEImRTISDDKZMIGY6SafyXy5VJ2AcX9bzVpJZ6pro',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjM0LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMTEyNjYzOTgtNWJmOC00Y2ZjLWE4MDUtYTZmM2ZhYTc1ODYyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.IyzTK7f7jU2ne1j39dkCg3vR6vFC_RXHHVl5ZmoQ1rg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjM1LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNzBkNjk3NjgtMTFlYi00MTIwLWE0MDctMTgyYzVhZWZiYTQzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Cb2DvDOsezPE2CviT8Y93EmTqOP-g9V1E6BVkqxLRI0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjM2LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMjUyZjgwZmItNmMyZS00NzFiLTlkNDAtNTY4ODA3Yjg1YTgzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.deLwbN42wZMxj7t5xLgWDVe6IOaVpCVRwjKwk_ETRuA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjM3LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNDQ1YWE2ZDUtMmViOS00MmI1LTg4MjQtZjIxMTY1MjllNzg3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ZkJbzT4NXBTAEV3nI1zTOowUgLPQiP1JznZlW6OSrZU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjM4LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiOGUwNjgzYzctMDM3Yi00YWFiLWIxZmYtZTJmODYxZWVhODNjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.OIBFV6xVWQ-K7pZztHB4G6i7ju1ZVt6Nrk6NiKLqtoY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjM5LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiOTM2YjgxZDYtMmNmYi00NjlhLTg0NTctNzVlYzM2MDczY2U2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Cmi_cfjcqdL3p3VgTJ8g1DVZpqAPh4jS7XqZjVV8Oyk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQwLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiN2I3OGFjYmQtNDJmMS00NDE5LTkxYzEtMWYxYTI4M2YxODBjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.c0LkMus4hlIXWMYT2kD85hGqAYepKx1_oYVHmUuHllY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQxLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMTc5M2FmZjgtZTlmZC00YzY1LWE4NjktOTVmNzQ1ZDU3MDhiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.pbOHq-eEmUBpzs1n7nC93uc9u5qWf4jRxJJNHOdMqBM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQyLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNjJhNDNmNjctZDFjOC00OWQ3LWEyM2ItY2UzYjllNjk3YmY0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.GE3QH-sdNKkXLqw6Ra-neFJjGXKlGI6w2bbGCcbWL1k',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQzLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiOGQzZWNjYzgtMDgxZi00ZmRlLTgwNzYtMDgxMDhjOGM3Y2M2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.EkJhhYNuhBd9tL15ajqxAEhpob6iFCEZcLi7DOoPPxY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQ0LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiM2NmMWJiZGUtOTVmZi00ZjQ1LWExZmYtNTQwYWE5MDc5MDAwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.sUu2mmuccvmPrx36Y-rdj6e5WZWfQk0jn-lo_WE6Qvk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQ1LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNDBlNjQwMWItZGQ5YS00Y2UyLWFkNjItYjc2ZTVlMzY3MjRhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Zpwnwv2mkcHW_e-iAlXy-1xZIBkEB94CwIjKmmKhPj0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQ2LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiM2RhNTI2YzMtYjY3OC00MTIxLTkyZDktNTg1NjEzZTlkZjU4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.d5VvMlbbrBqJx27Rn6NKOVZNtvMDbuNIAaSw9-1PnsM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQ3LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNDE5MGE1ZDUtODkyYy00NzlkLThkZDgtNTRjMDA2ZTNiZGJmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.JUcpayAhP-Q1GOhdb-vvVuoW2AV9-dt5gFFdHJv-tjY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQ4LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNWU4MGIzMmUtODAwMC00ZTI5LWJhNzUtODI2MGFhMzZjMDFlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.jhbAUeTwoapjSjDT0uW_8BbK1AZOgLB1sG_FKqr1uyM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjQ5LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYjZiZDM0NGYtNjQ4Yi00MDhhLTk2MDctNDFlOWJjOTNlNjZjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.POcNgjr_Fl_sqNB2Td2gAik3vLRpt7VIw_Bi4oYIIO8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjUwLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMmRiNzFkNjYtMWEwYS00MjcyLWE1YTktYWJlMDI5NzNiY2RmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.MCQz9steQtmakyMYDQdIxHb59HAg9Drq5oLFKRn2eco',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjUxLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNWJjY2EwZjQtMDA1My00ZGNlLWEwMDYtMDA5OWEwMGNmZWExIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.bmtLK53tQs64YnJ5Ywu5-C0Z1YdiTGEo3iE70apW2pw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjUyLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiZTM3OTI3MmEtMmE4My00MzM0LTg5NzYtN2JiNjJlYjlmMTNjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.m1WnaAKc18CuOKXnBDsKrxbotTKNavmKjkoKi3La94Y',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjUzLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMzM3YWMyMWMtZWMzNC00NWJlLWIyNTgtMjU3NzhkNThiZTg2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.7duuI01fDF16Fdf9joR1wfj4wS0O_Vo2fszSolxlH64',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjU0LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNjhhMTc1OTUtMmJiMi00ODRhLTkwMjgtNmZiMjcwMjQwYTFjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.8mo4qRoAf-sIHyZYTU47AWod1Zs6iNmSDhXV0zRBMgY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjU1LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiY2IyMDQwYmMtNDg4ZC00MTcyLTliMjItZmFiN2EwOWU3M2VkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.3sgxLiDcqCqZDgEiymbP2w4xOru_onjT1iUt7WYgwE4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjU2LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiOGJlZmM4ODMtYTc0Yi00MmFkLTliYTYtZjZmMTM5YTI1NWNhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.yCAvgUvok1dE-49Fs0KiFioc32GJq-eH2Wk34dP0Vdw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjU3LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNDU1ZmQxZjItOTM5Zi00YWMyLTg5NTEtNDdlNTY1NTA4OTZkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.UpUEg80bldcJ4ab01Uv-HuBOUAFg4oyGFx-f-ND2t80',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjU4LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiM2M5ZDA0MzctNjU1My00Y2NhLWJmZjAtNTM4NjFhMTU5YzFlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.KLkxL7YW_eSKKXZxxr5WPJj8_z3wlG_pkfW7WgPGeCA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjU5LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYzY4MzY3ODQtNTg4MS00OTY4LWE3NzEtZTEyZGM1YmFmMjVhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.KaLjQMbT4PmOGRkDHIPRv6ALTAWlMPFkJGDWIbTyjHY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjYwLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMmEwM2E4ODUtMjhmZC00MTRiLTlkMDItNTVkNmY5MTQ0YTk0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.oZKEB-nNAxrYQF7rBgfx9fkSH-QjWl4vz3TRCmgmDTc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjYxLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNWJhZmVkMzAtMThiMy00ZjFkLTk3MTgtMzliZjBjYmM2NGVhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.G08K88Vu821vDL2IBo3ICqYx6L1r3TTb-d2O-vIRZFo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjYyLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNjI2ODI5NmMtZTczZS00ZTg5LTkxNTUtMjczODNhZTQ5OTM0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.4m-sA4zfKt0zPfW_yZhS_I4nFPXFD24MU0-Y9bjeiUI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjYzLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiZDY2NTYwNTItYjQ2Zi00N2NkLWFjNGMtNjVhNDhhNzQ0YjQwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.bRFuMeMx9zeJoYUnloyJdqaDnaqSsAbRRMYgZCpkmwQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjY0LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNWUwOGQxMmQtNDI5Ni00NGNlLWI2OWQtMmE0ZWUyMzA3ZTZmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ADZKtqKPpyJlU5c2JzcaxwiIKpWFVfBR4Fbwl9XCNfc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjY1LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiZDM3NTJhMDgtNjdkYy00YWZiLWEyMjYtODMzNmZiZTkxOWIyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.PH45gL66vDrzYCmWJZCGNbK8vcndvc3cBMDHq_Vy8w4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjY2LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYjRlZGFkOTQtOWZlOC00YmRjLTg4NDYtNjAzODJiY2E2ZTJjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.2jYH6o3_qBWBlLEpnvD49QyfmAz0whxVmInFZEwvEy0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjY3LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYzU5ZDIxYTUtMTI3MC00Yzk4LWJkYWQtN2ZhYjVmOTJiZTQwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.5UgeeHVYezwVMD6wdqzKH0bzHogUsHTesC9tgC9EaLQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjY4LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiN2ZhYTBlZWMtOWRhMi00MTUxLWEzMGItZTM4ZjFlMWVjMWIwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.IghTwCkL41oTWonewGmHXYBUhyp2u0aRdquDaygO7BE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjY5LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNzI1YzdhODAtZGYyZS00YzU2LTg2MjYtYzViYTM0ZjY4ZmMxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.w4_y1cHN4_trzxVlf-1yw7Z7WlfuDCWt8jU_zC1SbDg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjcwLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiOWVhMDRiMTctZmZiYS00OWJlLTgxMTUtMTY2NDViMGI1NmU0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.dcnl4uCurWLhYTAblgziCCvTVoadUoz8zX65TIxXqvE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjcxLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNzNkYzIxODYtNzk1Ni00ZDgwLWE3ODEtZGFlYzU1NmI4Njc5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.h782rx5ddrqwhzuLRn5E_gEj_d5bS64wS0TzZ-YVk88',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjcyLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNGExYjQ1YzMtMjZiMy00ZWRhLWE0ZTMtZGRiZGE0ZjNhOGU3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.NMY5AvgrWUT2FRSjWwMipYMwOlQnZsutPvN21IwxSq0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NjczLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiODIxOTkzZjQtYjY1NC00NGY2LThmYTMtOGNmMTcyM2YxNzRlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.z6DuC5HxKTl8S5WMrZ5nBh2R3fa3-MCPYQpT0VgOpl4'
];


// ─── DRIVER TOKENS (users 1–16) ───────────────────────────────────────────────

const DRIVER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MSwiaWF0IjoxNzg3MTkzOTE0LCJleHAiOjE3ODcyMjk5MTQsImp0aSI6IjYwYzE4OWEwLTQ3OGQtNDg1Ni05NmUyLWM0YTVmZTExYTU1YyIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.DmLM9Q_3-9wSQIoQbOFCMzK1ijHVvI32e9evLfI8BbU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MiwiaWF0IjoxNzg3MTkzOTE0LCJleHAiOjE3ODcyMjk5MTQsImp0aSI6IjVhYjcxYTU1LTliNzItNDM5Ni1iNjgxLWZmYWE4OTc4MzZkOCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.K1_Ykc4UrKKRvd5Y6c1GE0-KRKl511WNuOYp1OZMwkc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzc2LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiNGU4MDFlNDItMTM3OS00ZGNmLWJjMmQtZTA3NWFmZjk3YTA3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.nrT7Yhx6-kJKQ5GYCAiXSDwpqaVWFqMkowBiN8xSc6Y',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzc3LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiMGU1OWNhOTEtNWE5Zi00OGUxLTlkZTEtMDYyMzVmYmFlOGUyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.iFuvR8bTewpl2QqIcko-D6rnbBvvgdK7uAsZmOpvWc0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzc4LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiZmM2MDAwZmItNGJmMi00NGM1LTlhNDUtMDYwMTNmZTQ0MjVhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.2ippf0lG_vk3Fd0pMbuydd2rDA2aieE41lCwFgGqDbM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzc5LCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiZTA0YTIyY2ItY2YwMS00NmY5LThiMzItMGMyOTFiNTE3OGE2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.rldijtxtg-K5PI7mEk_J6YCqAQLG4CpOARFg0zZ1XxA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzgwLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiYjkxNTRhYWQtZmYwZS00N2E2LWEzZWYtY2ExNzc0ZGM2NjQ5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.2-b-LsZd1aIlW1Hgg8s5vD2B-68699YwE8u02dnckyI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzgxLCJpYXQiOjE3ODcxOTM5MTQsImV4cCI6MTc4NzIyOTkxNCwianRpIjoiZjEwNDIwZmItMjg3NS00NGM1LTliZjctMDM1ZWQyZDE1NGM2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0._FAawIJZl9M7zICVVbD_Yk2o82dWrBMgnoAscYDlqTQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzgyLCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiN2I3ODc1NWQtNTBmMC00NWNhLWI2MTUtMGM1ZDIzZmU4YTU5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.tWOLdLVj9z3M_bE6BNCiIl6vWUQtZ_UZFnAe9H6B3gQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzgzLCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiOTY4ZmY2YjctMTFjMC00MzdjLWE4OTgtMTZiZTc1OGJlNDhkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.O16IB8HG_sx4YSjchGM1Og71Ti9Ezb0IyoQqzySWRhM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzg0LCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiYWJhODVmNzctNmIzNi00M2RiLThiZTctMWRmZTljYTVkMzE2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.7YAEno3co-7XWvzehljOQ-zKP6x2bNHkzdnBZ5_NYvM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzg1LCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiOTI4MGNlMDAtZjgwNi00ZDI1LWEyNTktM2M5NTI4MzJmODUwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.vh_Jf2ut1XCiM4lVwb9HReodrA1z1S6Xnb0HBSKn3ww',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzg2LCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiOWU0MjI2ZTQtZGRjMS00YjZkLTkyNDgtZTAxY2E2NTEwNWY5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.dnk4sWLN5LazPMLyEcSYNb0BC5m7xPD3HnXe8DU0Bic',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzg3LCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiZDhjMTdmNGMtZDJjMS00YjZkLWE0NzUtM2IwNjZjMjIzNTIzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.dhbAnCyj3CypREhcznn9tYhzqbwYbI7Vt2PYpzeFb2A',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzg4LCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiNjk1MTRmODQtMGRlZi00NmY2LTliYmMtZDU1YTE5OTFlZTc2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.WNuBayMzSFnVLcf6wHfc-5nOR7oceIHfPUo4jCwwikI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mzg5LCJpYXQiOjE3ODcxOTM5MTUsImV4cCI6MTc4NzIyOTkxNSwianRpIjoiZjQ1ZTM2MWMtZTFjOS00NzcyLWFjMjYtY2ZhNWRiOTA2ZmViIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.flobesnQLtXRIijcC9Qag5F9RJA7DxDTFiOtXqvH8pA'
];


// ─── DATA ────────────────────────────────────────────────────────────────────
const RIDE_IDS = [155,199,536,376,589,541,629,421,269,529,169,356,267,251,336,238,132,406,298,652];
const BOOKING_IDS = [116,119,120,127,128,131,132,133,136,137];
const USER_IDS = [1,2,626,627,628,629,630,631,632,633,634,635,636,637,638,639,640,641,642,643,644,645,646,647,648,649,650,651,652,653,654,655,656,657,658,659,660,661,662,663,664,665,666,667,668,669,670,671,672,673];
const LOCATIONS   = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ─────────────────────────────────────────────────────────────────
const errorRate    = new Rate('real_5xx_errors');
const writeLatency = new Trend('write_ops_ms', true);
const readLatency  = new Trend('read_ops_ms', true);

// ─── STAGES ──────────────────────────────────────────────────────────────────
// *** STAGE 1 — pushed to 900 VUs ***
//
// The original Stage 1 test used 400 VUs and got 699 req/s with p95 524ms.
// This test pushes to 900 VUs with the SAME 3-node × 8-worker config.
//
// Hypothesis to confirm:
//   - req/s stays ~699 (CPU is the ceiling, not VU count)
//   - p95 climbs to ~1,000ms (longer queue, same workers)
//   - errors stay 0.00% (server still processes everything)
//
// If confirmed: Stage 1 at 900 VUs < Stage 2 at 900 VUs in throughput,
// proving Stage 2 is genuinely faster due to more physical CPU capacity.
//
export const options = {
    stages: [
        { duration: '30s', target: 10   },
        { duration: '1m',  target: 900  },  // known point: 883 req/s
        { duration: '2m',  target: 1400 },  // watch req/s here
        { duration: '2m',  target: 2000 },  // ceiling probably in this range
        { duration: '2m',  target: 2500 },  // safety margin
        { duration: '30s', target: 0    },
    ],
    thresholds: {
        'real_5xx_errors':   ['rate<0.10'],
        'http_req_duration': ['p(95)<60000'],
        'write_ops_ms':      ['p(95)<60000'],
    },
};

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

function auth(token) {
    return {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type':  'application/json',
            'Accept':        'application/json',
        },
    };
}

// ─── MAIN — identical traffic mix to Stage 1 hammer test ─────────────────────
export default function () {
    const roll   = Math.random() * 100;
    const pToken = pick(PASSENGER_TOKENS);
    const dToken = pick(DRIVER_TOKENS);
    const rideId = pick(RIDE_IDS);
    const bookId = pick(BOOKING_IDS);
    const userId = pick(USER_IDS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS);
    const jitter = (Math.random() - 0.5) * 0.02;

    let t0, r;

    if (roll < 20) {
        t0 = Date.now();
        r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?pickup_lat=${(origin.lat + jitter).toFixed(6)}` +
            `&pickup_lng=${(origin.lng + jitter).toFixed(6)}` +
            `&destination_lat=${dest.lat}&destination_lng=${dest.lng}&seats=1`,
            auth(pToken)
        );
        readLatency.add(Date.now() - t0);

    } else if (roll < 30) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/rides?page=${Math.ceil(Math.random() * 5)}`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 45) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/rides/${rideId}`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 55) {
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/rides/${rideId}/book`,
            JSON.stringify({ seats: 1, pickup_lat: origin.lat, pickup_lng: origin.lng }),
            auth(pToken)
        );
        writeLatency.add(Date.now() - t0);

    } else if (roll < 63) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/notifications/unread-count`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 68) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/wallet/balance`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 72) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/score`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 75) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/profile/${userId}`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 85) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/bookings`, auth(dToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 92) {
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/bookings/${bookId}/accept`,
            JSON.stringify({}),
            auth(dToken)
        );
        writeLatency.add(Date.now() - t0);

    } else if (roll < 97) {
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/rides/create-with-route`,
            JSON.stringify({
                from_lat: origin.lat, from_lng: origin.lng,
                to_lat:   dest.lat,   to_lng:   dest.lng,
                departure_time:  '2026-12-15 09:00:00',
                available_seats: 3,
                price_per_seat:  5,
            }),
            auth(dToken)
        );
        writeLatency.add(Date.now() - t0);

    } else if (roll < 99) {
        const phone = `+96277${(Math.floor(Math.random() * 9000000) + 1000000)}`;
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/otp/send`,
            JSON.stringify({ phone }),
            { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } }
        );
        writeLatency.add(Date.now() - t0);

    } else {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/user`, auth(pToken));
        readLatency.add(Date.now() - t0);
    }

    const is5xx = r.status >= 500 || r.status === 0;
    errorRate.add(is5xx ? 1 : 0);
    check(r, { 'not a server error': () => !is5xx });
}

// ─── SETUP ───────────────────────────────────────────────────────────────────
export function setup() {
    console.log('\n' + '='.repeat(65));
    console.log('  SyRide — Stage 1 Ceiling Confirmation Test');
    console.log('  3 nodes × 8 workers (24 total) — same as original Stage 1');
    console.log('  Pushed to 900 VUs to match Stage 2 pressure');
    console.log('  Hypothesis: req/s stays ~699, p95 climbs to ~1,000ms');
    console.log('='.repeat(65));
    console.log('  WATCH IN DOCKER STATS:');
    console.log('  App CPU stays ~380% from 400 VUs onward → CPU ceiling hit');
    console.log('  req/s plateaus at ~699 even as VUs climb → proof of ceiling');
    console.log('  p95 climbs with VUs → longer queue, same throughput');
    console.log('='.repeat(65) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error('Server unreachable — is Docker running?');
    console.log(`✅ Server alive (HTTP ${r.status}). Starting confirmation test.\n`);
}

// ─── TEARDOWN ────────────────────────────────────────────────────────────────
export function teardown() {
    console.log('\n' + '='.repeat(65));
    console.log('  CONFIRMATION CHECKLIST:');
    console.log('  ✓ http_reqs/s ≈ 699    → throughput plateaued at CPU ceiling');
    console.log('  ✓ p95 > 800ms          → queue grew but server never failed');
    console.log('  ✓ real_5xx = 0.00%     → no crashes under extra VU pressure');
    console.log('  ✓ App CPU stayed ~380% → same physical ceiling as 400 VU run');
    console.log('  Compare to Stage 2: 883 req/s at 900 VUs = 26% more throughput');
    console.log('  That gap = extra CPU capacity from 2 more physical containers');
    console.log('='.repeat(65) + '\n');
}
