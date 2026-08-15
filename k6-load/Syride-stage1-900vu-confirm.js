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
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUxLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiN2YyMmVkZWItZTM3OS00MDI3LWFlNTgtNzJjNTViN2MwNDRiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.AliOAhI_kgzB68V5YzIiDyAt0jlaNwawQv-jIZTfMRY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUyLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNmRkMTBhNGUtYmNlMC00YzRjLWI4MmYtYmVlMjA2M2EzZTUwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.O5tMfZwQNl-zcRnPW9JJmpD-Ch3930KTAuVqqjhy1t4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUzLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNzMxMmY2MmYtMmU0ZC00MzgyLWFjNjYtODZjM2UwNzlkYTQxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.hosIxw477R5TT3k8wAWyLlnEq-3dMdhYxj-9EJKHkFs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU0LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNDk1ZTkzY2EtZjk4Ny00NTlhLWJjMGEtZWI0NDVmNDVkZjE2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.y5I9axUOzpm8HqAvsQVJTu4w67vVSau8MpBubKbNbN4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU1LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiZWMwOTE5YTMtZjhkZi00OTNjLWIyZjEtNzhiZWYwZGMyYWY1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.mzja2MC98tq3YUiFnlGQV-F3fXsa_BvFRzwTUJ1y71I',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU2LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNDlhN2M1NzEtMDAzNC00NTYxLTkyOTMtNmMxOGViMjU3YmY1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.PRZ2buDWzjGImpvWMtju1MoORiNIkhpya6zqGlpHub8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU3LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiZmM4Y2ZkZTgtM2I1ZC00OGFjLWEzM2QtZWE2Yzg4N2JiZjZlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.BmDdIHtfgR8v6TCUkATWX74yAbpr8KpFAPx-bPpTgNI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU4LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMWI1YmQ1OTQtZmRlYi00NDEyLWFmOTgtZTkxYWM1MGU1NjM2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.S1iRKde-SPL0P0_OxZfZP2Gygw6GDcjGSfshpRscGtg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU5LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiY2MxOTEwMjMtN2FhMi00NzU5LWFiZDItMTA3ZjhiZDQ2ODY5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.F0H_YD-MO7R6MggNo7CITEJGfN9WG5V67E7sWbitW1M',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYwLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMmE3OWQ4NjItNmU2Yi00MjRkLWI3YjEtMWZkYzlmNDIyZmNhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.uJp8Ag7FTjoH1z_xrra1sXtg9K9oKj1SQBpxbS-Drm8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYxLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNGU5ZGY4OTktNzQzNS00YWU2LWI1OTItYzA5YjZmZmU0NzFkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.bb1ilZQ5TrfZU7_M4yNf_jmxjmfeR_XXwI_5XYyscdE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYyLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMGNhYjA0NDUtYmNjMi00YmE0LWI5MGMtMTRkMGJhZjIzZGJiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.XJA1hpucH7RZtF8gNAPzUaqqNzuEoE6Xyux3ieJ0MSc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYzLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiODM3YWNlOWItZDdlZi00MGI4LThlM2YtMzBkMWQ1NGIzNTgyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.VkfUAW9La1x2fcJ7Te4-IQ_hper7jJJDbVdhM1d6vgU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY0LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNDVhMTRiNjUtZmU1Ni00MjNmLWFjZjAtYjIzODA3YzlhMmIzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Rk4XG6pxWYxyPYDJyDGpDKw02AR6SctmS1WV5bPUDXc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY1LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNTMyMjM4MmEtMmFkNS00OGRlLThjMzQtNzhiMGFiMTJmOTAwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.otcEDAnKBI189Ey89N-vSbOVTFxRtq1cO9AyEX3cOVw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY2LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiOGM5MWQyMmUtNDFiMy00MTNiLTliMjItNTc3ZjEwNTEzN2U0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.p3zokIbsUIk1sKi4ct8LHHkuMJ8RZFamOiDOfUJDNlg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY3LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiODhlYTBkYmEtODBlMy00MGQ5LTk4MTItYThmMTc0ZjE2ZGI4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.hkCVukovLbS1NGI8OfVzkgrWLpLbACxpQDmTtVXTyP0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY4LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNTAzNDA3M2YtMzlkMC00MTdjLTllZjQtMjMyYzJmOTVmMWJhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.LfaVCBYZsoQsgRkmXjdc16xS5VitpF3ZWuufCuO5YJY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY5LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMDk4YTM1NzItODRmYy00YWI3LWI3MjAtNzRlZTJhZDg1MmJkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.2HBkEqkQl-BoVi37doinVUcj8Dsxw6k9xnIuSBAOa-c',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcwLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiZDZmMjM3Y2EtZWI0Zi00MzE0LWExYzgtMTEzZDRlOTFmZDA3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.FD0RAbwJ87TTwOuecrBdmk4GOqFxAIEG3LfxOslc0dA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcxLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiZGVlOGE2MzQtMDQwOC00NDFhLTk2OGUtMDI5YWM1Y2I4MjI2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.IjyNHgLQjkKpg5_50SLC_pjvDusSWDKHJbE1my_XEak',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcyLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNGI1YWU4MmEtYWYxNy00ODUyLWFhZDEtYzRjZWMzZjc3NTY4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.xn81RETIESUtWkUTSg6ZPeWmSJpW5NVLCODLVmorSvE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjczLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiZjAwOWMyZmMtNWEwYi00MTg2LTlmYTMtODA2ZDA3MGI4ZGYwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.LJ--9kjoaIv5TKDjyB-q-pdA3l5t_vaKjRsaZ_ZsopM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc0LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNWIxN2MxYzAtZDNiMS00MGE3LTkyYzItZGQzZmY2MGJiYTI5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.BDeD5YLeUQlVjPdVtw7Ijbr5YXCVv1CWCmZ2LJRqq6g',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc1LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiZWM0NzY2YjQtNzY4MC00ZGZmLTgwNzQtMTgyNWRkZmI4NjhmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.C47VD_7hERTCvctMg64IbRfbt5S0On8CKRMEPuy0SH8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc2LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNGZhYzFiOGMtZjVlOC00Y2QzLTliOTgtMTQxZmFmNmY5YWNjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.a3cahuiuqX15Yl9oMRPMamnOA0J5dlzfipcJI36nU38',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc3LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNjg3NTZiNWItNDhjNC00YmY5LTk3NjAtNDQxOTJiMjdiNTkwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.yPzQYa3KiHtfnKYzIcjwnNyiomoNMhf0fuw3NW7b6nQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc4LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiODYxYmExNzYtNWVkZi00YTBkLWE3NDYtMWY1MzEyNjlhZTU0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.eQDoLlWdxKneWIwAiNFswjdDmH_2kYQiHE3IM-J-q_o',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc5LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNWJiZjE5MDEtYjA1Yi00NzBmLTk1OGYtNWM0MzU1YmM0MjEyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.exDA_96PiOumpl13ALmE99Wapg7CM2KSlOj5gIwMhzg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgwLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNzhhNmVlMzYtZGVkOS00MTg2LTgxNjktZDMxNmY3MmIwYzQ4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.bx2o6NP_BHm1RcT7FTJPtNbiVNpihX-2hENdQsX4HhU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgxLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNzc0MjcwZmEtYWFlZS00NjIwLWJjYTctNWFjODhlMWI0OWU3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.5kbp8-7gBUxCqwwsAoaEol22lV79_s8MdrtQ3huvn3Y',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgyLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiYTM2MDcxNTQtMjE5ZC00MzUwLTg0NTctMjA3YzVmNmJmMzkwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.mmxzMQFF7HovWRQfslP6JM-P1FrbH79t83Nkgoe19Us',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgzLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMTg4YTIzYmQtZjgyMS00NjJlLTkwNTMtNjY4NTMwZDMwZDNmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ixzKrNtV4BgRtgBf5EWq2C8TJDS-ZKEAuUVhx5fw4sM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg0LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiYjlkZjRjZmUtYThiYi00NmZmLTg0NGMtN2UxZmRiNTg5OTEyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.yenqupvpxycXKG5EUEeQ33IIwgx-sqau6XBtTWMrZjI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg1LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiYjMxNzk2MzItOTliYi00NTMxLWE3MTYtY2NkMjQyNjMzY2ViIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Xnpn9xE020OcGZxcVITsJT20wuprnrb3M4WaE45doq0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg2LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiYTczYjVlMzItYjBmNy00ZjAxLWE5YTUtMDMyYmY0MzFmMGE2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.9dBiTVjgo5MF7ATou9ouKrdtgHLyLGJQ-7Nfco2tKmE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg3LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiOTA4M2Q3OWYtNzkyMy00OTQyLWE3ODktYWFjZDdjMzI2NDljIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.YdQys1A4bpQsw2nkLGe16vxGjB6DGITviVQf_yBSSkQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg4LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNTkxYzFkOTEtMDNiZC00NmIzLTk2MDYtODk0NGRiNGVlMTZjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.yBjrWSTtyBjBkwbjhdJmYqq1F5wAi6D3WN1eRj_VDdc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg5LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNDMyNzAyMzQtZWFmNi00OWNjLWE3NjctY2NiMDA4MjljMGEzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Mi-dpEaDYks-HmJVjq-DiCZguNvDZJUuT4RwLnwr5Yw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkwLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiOWQyOGZjNTUtNGU0NS00MjZiLWEwOGItNmQxM2U5NDU0YzU2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.btoKUuk-_eGH9c2otxpoOH58y_82Xf-kzfwVdYDfWYY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkxLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMDEyNTZiMGItNWUyMi00YTI0LThhOTktODNkOTJmMDc4YWIyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.yICglGzTXO_KNd5RYvjKu50s57YHhSlytob10yQFF70',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkyLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiYTI3ZmM5MzgtMWI4NC00NDViLTg0NTktNGRmNTMzY2U3YTY5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.-wU_KgJwzNBEFSHXKqCKtLHi0zo5eubWKrH0J15PvOo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkzLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiN2MxOGRhODAtMTliNS00ZWVhLTg3ZWYtNDRkMWE0MzQyN2VmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.B6OrLL4XMbpRmma3QDQAIVzVp7aXvLrIGTGaZgZY_SE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk0LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNDg3YmI0OWUtMjkwZS00MDVmLWFkOTYtYTJkMjNiMzEwZDYwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.O6fIk9HTgbFk5lgF-r9XqTT3IOZzdIE6MdRrQHkqbR8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk1LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiYTE4ZWJlODMtM2ZkNi00MTlhLTgxYWYtZTM1ZWM3NTUwMjNiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.M9Fxh8CXWPvPr4J3KVuyAkeiCD_d4ZaFQ6OsiaRL68g',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk2LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiOTQxZDI3OGYtZDEwNy00YmZlLWFkOTEtMzQwYjRlNDJiMWJmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.tGPnrvwVlnyfIziCbX4KEdlmMna8V_YX4MQQQxzbuuY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk3LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiOTE2ZDk5OTctZGJmZC00ZDZmLWIxODgtM2MzNzBlMzZiYzUxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.RVuaGjbFMevU_LaxBwSQmYmZG2GsRsB7u3X_zrKU-Vk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk4LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiMGY2YWE5ZWMtMDgxOC00MTg4LTkzMzQtMjc0MTVlMGE5MjliIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.eY16eOYRn0G4ffhxnHIAKP81IwPbAEAdeXT4ThuBx2A',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk5LCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNDViYzMwYzQtZDBmNy00YjI0LThkMzItMzdkMDcxNjlkNjljIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.B_4QfaCPFaNkJ9cIkqHMg9jLuhoBxS5SWqg9IPn-ZfQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzAwLCJpYXQiOjE3ODY3NTgxOTMsImV4cCI6MTc4Njc5NDE5MywianRpIjoiNWM4MTM2MTUtNmQ3YS00MmY2LThhYjItMWE2Zjg1NDIyY2U1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ViyGk81tLJ-FK0rFGODn9Wz5Liv-mMS__H7UhMZkTV8',
];

// ─── DRIVER TOKENS (users 1–16) ───────────────────────────────────────────────
const DRIVER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MSwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6IjBmNWI2MDgwLWU2N2EtNDM3Ny1iOTliLWZiZDU0OWU2NDFiZSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.R_poWYhk51D5J6wzu6pw-c0wAfWRbkc6LgZnXAISHzY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MiwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6ImM1NmYwYzRkLTk3NzQtNDYwZS1iNzllLTY4ZmUzODIzYjM1OSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.U2bOH3wsxRXao1j5Mm9_hYJCq20VKZmCpKsLubVpkQE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MywiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6ImI3NDMyMTMwLWU3ZjAtNDUzZC05OGRiLTIzNDhhZTQ3MzcyYSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.f3y-EvyLMnylaQLVjqxXtqP0ogxoW_Yg5apgO8X-MEc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NCwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6ImFhNGE5MmI3LWUyNWItNDU2OS05OWNkLWU5MGQ0YTM2M2EzYSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.jAmmcguaZldLv23tkr86fFHeey5jFH4d5i9t-gP6DCo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NSwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6IjQyMTcwZGVhLWJkYjctNDdhZS1iNmJhLTNjZWFhMzYwZGFjMyIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.jDWeGZbDbSDgiqoNBBeP6LDgLAbF71xnZAsOo6CvuTc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NiwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6ImZjNTYwNmZjLWE5MTMtNGYzZi05ODEwLTFlOWIwZmMzZWEzYiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.CcJu9n8lIz-sWy4xK7Xah7k8QOs6LN9pXQhlMfVhyjE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NywiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6ImI2ZWEyOTBiLTEwOWUtNDc1Mi04ZDcwLTc1NmI3M2Q1MzgwOSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.cT9ctIuMO5Qdc8gaDBE8oaC5Q5IXJbO_65dyCoZG51w',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6OCwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6ImM1MzUyNTZhLTIzNDItNDllNy1hNjBjLTY0ZTQ3ZTUwMzRmYyIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.ZegU4MjrHExVFxcvYR9kZNI-VPRTEmq9M1vo_8YyI8E',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6OSwiaWF0IjoxNzg2NzU4MTkzLCJleHAiOjE3ODY3OTQxOTMsImp0aSI6Ijc0YjU0MmEyLTg3YzctNDQyYy04N2Y1LTdkN2Y3NTQxMzU1MSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.f901oXiXEjBtUkxFN3llsPUpPr1cV80ODhPoP8HwZdU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTAsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiJjNDBmMTVlYS1hY2ViLTQyMmUtYjZiMi01N2NiNmRjMWVkNDkiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.gEWvyxj3uP8gtIkrhp8U1d8jH8XEQ-6eaQucPeRggis',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTEsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiJiZWYwMzQ1Ni1lNmUxLTQyMzEtOTRjNC02ZjI3M2FmZjRhODQiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.1A2QVLVxxao_uxrPqf6D7wkPMXoqHkQQrB3tRUu8zSM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTIsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiI1ZWYxZDczYS0zMTFhLTQ3OGQtOTM0MS0wNzA5ODYzN2NlYjgiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.Vb7PHZ4lupdJsYZ_vWY_YT2ZZSjxlDYlWBkK9wBQ1F8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTMsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiI4YzZmMGJlOS1mMjJmLTRmOGYtODBmNy04ODA4NmMzMmEyZTAiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.mbCcivjcYRVC5BnaxOc0GZS10da_VU6ay_Fkv8AZ3c8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTQsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiI5ZDQ0MTYwYi1mM2I1LTQzYjAtOGI4OC0yM2I0YjBjNGY5MmMiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.QsP8ErmbbH1lDvnunrsW2qphjTB8VNOEeyPy6-TM0x0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTUsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiJkMGFjMmQwOC1kODRhLTRkNTAtYmVmZi1iOTg0NjJkMmExOGQiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.9XVFfaEZ8IN2B0u6shfDXAZ-oBvJgsuQNM3RlW4WDrc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTYsImlhdCI6MTc4Njc1ODE5MywiZXhwIjoxNzg2Nzk0MTkzLCJqdGkiOiIzMDc5MTFmNS1hZGQ0LTRhNTAtOWU5OC03NmEwYjM3ODVmYWIiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.x2hwkGEp0cmsHMTyL4Q8hPPWIvthDZK8nUcjfYWMJK8',
];

// ─── DATA ────────────────────────────────────────────────────────────────────
const RIDE_IDS    = [764,20423,17020,29270,34028,33270,37965,38201,41049,49316,69132,73016,69392,75041,93396,107954,107908,115258,130153,150173];
const BOOKING_IDS = [3901,8574,9044,9178,11182,13253,18003,19065,20689,21267];
const USER_IDS    = [251,252,253,254,255,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,298,299,300];
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
