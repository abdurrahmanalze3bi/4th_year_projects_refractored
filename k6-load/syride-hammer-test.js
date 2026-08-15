/**
 * SyRide — Hammer Test (Breaking Point Finder)
 *
 * Strategy:
 *   - ZERO think time → maximum RPS from each VU
 *   - Write-heavy endpoints ONLY (booking, ride creation) → bypasses Redis cache
 *   - Ramps to 2000 VUs → will find the hardware ceiling
 *
 * HOW TO RUN:
 *   k6 run "k6-load\syride-hammer-test.js"
 *
 * Watch in second terminal:
 *   docker stats --format "table {{.Name}}`t{{.CPUPerc}}`t{{.MemUsage}}"
 *
 * You are looking for:
 *   - MySQL CPU crossing 80% → DB is the bottleneck
 *   - App CPU crossing 80% → PHP workers are the bottleneck
 *   - p95 crossing 500ms → degradation begins here
 *   - error rate climbing → this VU count is your hard limit
 */

import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

http.setResponseCallback(http.expectedStatuses(
    { min: 200, max: 299 }, 409, 422, 400, 401, 404
));

const BASE_URL = 'http://localhost:8080';

// ─── TOKENS (same as breakpoint test) ─────────────────────────────────────────

const PASSENGER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUxLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiOGVmODQzMTItYThmYS00YTM5LTkzNDEtODcxYzVkMjk4NzdiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ZJHUtRielWfpCgBKXXT-SYpKy6iL1vv-5aAKvUkwNfk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUyLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYjIwMGM4NzItMjFjYy00ZmRjLTgxNzktODU0OGJlM2Y4NWMxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.YS5ITSWW3OIWn1LqPeZFGpqlxeIDGEKgs-vrd3RWX0c',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUzLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMTgxMTQ0YzMtOWY4Yi00Y2QyLTk5NmUtOWQ4NjcyOTZmOGE1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.njKhyLpEwlew_dG75nVv6h_3O-fANcfu3ItbCdiAin0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU0LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMzdjZWVmNGItZDczYy00YTI0LWI3YTktNTMyNDliYWZjOTAwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.eELX08cAsL91HNsvhh5eq7_Rao2ZUmfIy2nhuyRp8tY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU1LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiOTMyNDQ1OTAtZmRhZS00MjllLWI2MWQtMTVmNjBmYjU3MzAzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0._VtFuTqiMDFSJU7kxSvIisc69LofaRMb9PJxAjsOu5A',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU2LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZGY5YTI4ZTAtN2YxMS00NjE4LWFlM2QtN2IxZTI0Zjg3M2IwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.eCgfaNnqIIKjMsG2YMlY_nedxBHOFpCOnNlvlO8bDso',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU3LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMzRjYTAwMzctMTc4ZC00MjlkLWE5NjMtYzhkMDg3OWNmM2NjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.v-daq2Y2lowJQ6k92w6FeRrDlg-9ezbHaRjnd4JNgT4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU4LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZjNjZmM2NTUtNTNiYy00MDk1LWJmZWEtZDAwYTZiZjcwZTgzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.OnhvY1b-_MUVnJ8E8AURm8LpSO_kh6ohaOuGTjswsso',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU5LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMzhmNDJiZmItODRjNC00YmZhLTkyNjctNjZhMWIxNzU5ZjFhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.UQhglku1sF0d4MM8eZxNVST0Ph5dpi4qKWlOS8csz2c',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYwLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiY2Y3OTYyNmUtZDlkNy00NWRlLWIwZWQtNTNlYTZjMTZlNDY0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.UfYv7Xp8ZqsciCTOw8kZVuxCK2uoIes8qxxFo_kuuSI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYxLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYzA1Yjg4ODQtMmRlNS00NTYyLTk0YTktMDMzOTlmM2Q3ZWRkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.E40LGMCbvWTuBlboMsWkBhmnPqJo73AODspKdEjOVkU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYyLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZjFkZjhhZGItZGI2Yy00ZTA0LWE1NjEtN2Y4ZmZkNjE3M2NmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.uXQjth8OnHvqxzmaW45FQxL6dghL4Dui7tA4wMjRjB0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYzLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMmU0NzU2MDktNzNmMS00NGUzLTkyNDMtMTczNTk1ODEzMDgyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.kihejv3KSuSYN1QojSa5q0eUVb6RIQrdVtZLU8Rip_8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY0LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYmRmNzZjMWYtZDI2ZS00ZjhmLThhMTItZWM1OTA0ZjQwYmM2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Gjb7ztRtZgAWz3XF24fF3J9ms6MiQlQYVyCUZZNvZpQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY1LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNjI0MjI4NWYtOGU4MS00ZWNmLWEzN2ItMDQ2ZDYxNzA0NTU0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.k8cZ1c18u1Tw5WfRPi0ikQLontY9Zat1s37SIPSTJec',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY2LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMmE1NDBjYzYtOWJkMS00NmNkLWE2ZWQtN2E5MzAyYTUwZDUzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.uNNsZQlZZ1OCDlfjACuBzxlN3H0GC_Bvhtfi_Vh2nzs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY3LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZjE0NDkyNzktNjU4MS00NDY5LWFlMDgtYjZjZTJkMWQ2YWVkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.RFb0TPELJxnA9r0H6_oNRaZtWjaU_21KSkp8n1AbyrI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY4LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYTJlMzMwZjItYTk4OC00MWQxLWE3OTAtNGQ1MmI4NDU3OTRlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.2rAwBqw9hVjhzuh3v1u4j4lII3kfzdJFqwvskvpyD2s',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY5LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiN2QxNTE0NjEtYjA3NS00ZDVhLWJmODQtNzIzMmU2MTU3NWM1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Fxv5kQy6iEdyj1QFx-OcKTvStDuNZZilsMGjpr74wM4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcwLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNjE5MjM3NTItNTdiZS00NDU3LWE2YzQtMGMwMDg2N2MxZWZjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Mx2yJ1Tsc7sISLfSJGeLCbV3tvKzPcZGAt6uNEnPMlQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcxLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiOWFiNDczOGUtODcxMy00YTdiLTkwZjYtN2U3Y2U0NmJkYThiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.miCcfA5eQIPesBctY3jRYEETGwCwD9r_bXOZqnuueek',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcyLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMjMwZmQ2MmMtNTU1Mi00YTE5LTg3ZTItOWNiZWVlYzIwMzQ5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.nQenh9GfPBdn6-g5TlccdP7RZm6feUOm4QfZlec1vDk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjczLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMDkzZjE3MjUtNGE2NS00OGM0LWIwMjUtYzY0ZTQ1YmNmYTQ5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.AVcMNouxijEjxbz5HQxfoBtPvrvnzDzQq6fDcwpDipY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc0LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMTFmNmI3ZTgtMTUxZC00YjdhLWJiY2EtMmI3YzkyNmQ2NWExIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.aZk2HC4oNvnt1n4yryf9FveiFFEzD7eyqhS-o8ihKyg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc1LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZGM3YzE5NTUtN2U3YS00Y2U3LTkwOTgtYTg4NWQ1NzQxZjhmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.CQwdsIJ22-tWWPABMVHZr1xVj905TFPpSqehRBd2v-o',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc2LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNTQ4MDliZTktZTU2MS00OGY1LTg0NmYtZjcxZmM2M2MxNjgzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.G7EhsPslwOKjEkZVHwLTEP4kpa_AE9rlzy6mM8EJJPQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc3LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMDE0OWNkMzQtZDZjMy00NWYxLWI4NGItYmI5ZjdkNGRhOTJmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ETBpsZXantDpvRRsoUGTSAbRp9VAoS5n8fDujuY_OgY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc4LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYjI4MDBlN2EtMDJmYS00NWM4LTk0ODctZGY5NDg3Nzg2NDExIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.mrMP7m2k8IccQP9xNgbKIbEeidIaknkkY3tfd7Lwyko',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc5LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiM2U0MjQyM2MtMDhkYS00NDFiLWEyMWMtMDUzOGQxYmRiMzNlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.6Jk-26DZ27FoRWcCX_bE7s2FyheCduaZbY3BrIrtFy8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgwLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMzU0MGQ4MTktMjllYy00OGI0LTk3ZDItNzFiZjk3MWE3ZTE5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.-TWnVfvnpW8fay3Il9tTva_xp9aMk8WxXIpjqnxfPz4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgxLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYjM4OWUyNjItN2Y0Ny00MTZlLTkwNjQtMjg3YjlmYWNmYjYyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.rVtcDmyP7D-cshI0VDW6AtYhehCPTzPy4SA_j1Jbrr4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgyLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYzc1NGZjNGMtMTNhMi00ZjQ2LTliOTEtYjRiYmMwMjMyZDZjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.rD4p24ZJ_y1qQde7VsrMGn1zevd-qZtYQ4EtvI-9R9o',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgzLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZmU2ODYyYzEtZTNjNy00YmJiLWFlZWYtZDAyOWU2NzYyY2NkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.D9bAztr2n8J6kbZfyBZIZStbOA5mHJFVH2ma4Wm9kyA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg0LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNWNmNDIwMTEtNTcxOS00YjZjLTkzZmQtN2I2Yzc5YjNkZjFlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.lg-xxsFCgSp9lpEFY8ZbTZzqXwLoIuJ5A6BWXV5MYPE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg1LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNWE2Y2VmNmYtZjNlOC00NjQxLWI4YzgtYTk3ZjA5NDZlZmI4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.kDpyU9cweMeuHitHkpcSX7urQhV8YEIov8Ncpcq2P1Y',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg2LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZmMzNjNmOWYtY2E5ZC00M2YwLWEyNzgtZGNlNTJiZDU0ZGQyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.IghS_L6LA4Jzm5Tj3-ctO2TyK0uJz--1EiMQ7oULgLU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg3LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNmUzOWI5ZTYtYjA4My00Zjc4LTk2YzktNWY5MGNkZDdjMmRmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.cKl2TbJ_w9XHGqRZJ55KmsuhLYiKxXr1xHnmMyVfgvg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg4LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMTUxM2RlNGItMGEzMS00OTVlLWFhMzgtNTQ5ZTI5MGJmMWExIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.WtR-u9FJQ4Yi8ReuxG2j1KZF9ddfndoLeFA2BeEy61M',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg5LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiOWQzYmU4MzEtMWQ1YS00NzUzLThmMmItMjZlNDRmMmU3NGM1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.rlQCZwWHHl546vC8TPzlu0wk2z8n9RM2L1yn20xcTWQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkwLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMjM3OTRiOTUtOTE4Zi00YzNkLWJkOWYtMmYzYWRkY2IzMmNkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.hR1VqcI6CEloidOA_d8ta-f4pHcqwpUMf4LFwr0IZfU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkxLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMGY3NTBjNDEtZWZjMi00MjM5LWE1NzItMzE2NGU5ZmEzZWRjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.YKtsYGXAXt2W1VjaA5IHWz__EX4dn70H64G_ZPeSxEQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkyLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNDU4N2IzYzUtYThhZS00OTc3LWFhYzAtYzA5NTQ2MDdjNTU1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.VDTBy7McD8FyKKtZ0X430sfOD50a0zUbxEODCls90l0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkzLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMjhkMDg2ZTItNzcyNS00MmRiLWE0ZjYtY2Q2OTJjMTI5ZDUzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.WQOi1NcWc5_vLcaA2eZTuYIF6Ib5XdwpuoR4W2ZxwFg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk0LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZjAyMzhlNjgtY2UwOS00NGJhLTg3ZjgtZmQ2YWUzOWRjNjA1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Ddz3GMpcBX7aAx-v4_AcUfgOHsVgq2qmB3y5d1bUG58',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk1LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiN2M4Yjg3NjQtYmFmMy00Nzc1LThiOGQtMWZlNzkxMzUxMzgxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.VLssypuq95YuybMqEaPl5ezTlSa3Hm5lHPJvsnaGtdQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk2LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiYjE0OGNiN2EtZjcwZi00N2RiLWI5ZTQtNTA3MjYxYWM4M2MzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.9OSgRIckz_A_cS4naAik0_w2kg2BlOJ8a3GxCktPmTg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk3LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNWRmNDQ0OGYtM2M4MS00OTJkLThiZDktODhhOWU0N2M5ODEwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.YxOWzNAyMddvH78iq23y3izPhAdBqbeK8rnl4mf7nbo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk4LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiMzIxOWZlNDYtYmYyMC00MTA0LThhYWYtNDU0MWIxY2Y1MWY1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.60rQpRErZK9j20n0XA01DPrcA0XK-ZI3CxztxAF5vUg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk5LCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiNWYzNGI3MDgtOTI2ZC00MTRkLWJhODQtZmEzM2U2MmJkMjk5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.F9FDbK5eZjXmCI0Nesm7C_7KGWMLKVg0dNNVDylzLn0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzAwLCJpYXQiOjE3ODY2NzA0MTEsImV4cCI6MTc4NjcwNjQxMSwianRpIjoiZGEyNTFmMjMtZDdiMS00NTVjLWE5NWMtZGM0ODk3ZTMwNzlkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.owPkeNoreEjRaxnQuEQbLjTFvDfqzy5vPm8ONL6-ujU',
];

const RIDE_IDS = [2, 3, 4, 6, 8, 11, 14, 15, 17, 18, 20, 22, 23, 29, 31, 32, 42, 43, 46, 48];

const LOCATIONS = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ──────────────────────────────────────────────────────────────────

const errorRate   = new Rate('real_5xx_errors');
const bookingTime = new Trend('booking_write_ms', true);
const searchTime  = new Trend('search_ms', true);

// ─── STAGES ───────────────────────────────────────────────────────────────────

export const options = {
    stages: [
        { duration: '30s', target: 100  }, // warm up
        { duration: '1m',  target: 300  }, // climb
        { duration: '2m',  target: 600  }, // first real stress
        { duration: '2m',  target: 900  }, // heavy
        { duration: '2m',  target: 1200 }, // find the wall
        { duration: '1m',  target: 0    }, // cool down
    ],
    thresholds: {
        'real_5xx_errors': ['rate<0.10'],      // fail if >10% are 5xx
        'http_req_duration': ['p(95)<10000'],  // wide open — we WANT to see it break
        'booking_write_ms':  ['p(95)<10000'],
    },
};

// ─── HELPERS ──────────────────────────────────────────────────────────────────

function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

function authHeader(token) {
    return {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type':  'application/json',
            'Accept':        'application/json',
        },
    };
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
// No sleep — every VU fires continuously at max rate
// 70% write (booking) — cannot be cached, hits MySQL with row locks
// 30% read without cache benefit (search with unique coords)

export default function () {
    const token  = pick(PASSENGER_TOKENS);
    const rideId = pick(RIDE_IDS);
    const origin = pick(LOCATIONS);

    if (Math.random() < 0.70) {
        // WRITE: book a seat — auth check + SELECT ride + UPDATE seats + INSERT booking
        // This is your most expensive operation and bypasses all caching
        const t0 = Date.now();
        const r = http.post(
            `${BASE_URL}/api/rides/${rideId}/book`,
            JSON.stringify({ seats: 1, pickup_lat: origin.lat, pickup_lng: origin.lng }),
            authHeader(token)
        );
        bookingTime.add(Date.now() - t0);

        // Only count 5xx and network errors as real failures
        // 409 (already booked), 422 (no seats), 401 (token reuse) are expected
        const is5xx = r.status >= 500 || r.status === 0;
        errorRate.add(is5xx ? 1 : 0);

        check(r, { 'booking not 5xx': (r) => r.status < 500 && r.status > 0 });

    } else {
        // READ: search with slightly varied coordinates — harder to cache-hit
        const jitter = (Math.random() - 0.5) * 0.01; // ±0.005 degree variation
        const t0 = Date.now();
        const r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?pickup_lat=${origin.lat + jitter}&pickup_lng=${origin.lng + jitter}` +
            `&destination_lat=${pick(LOCATIONS).lat}&destination_lng=${pick(LOCATIONS).lng}&seats=1`,
            authHeader(token)
        );
        searchTime.add(Date.now() - t0);

        const is5xx = r.status >= 500 || r.status === 0;
        errorRate.add(is5xx ? 1 : 0);

        check(r, { 'search not 5xx': (r) => r.status < 500 && r.status > 0 });
    }

    // NO sleep — this is intentional
    // Each VU fires as fast as the server responds
}

// ─── SETUP ────────────────────────────────────────────────────────────────────

export function setup() {
    console.log('\n' + '='.repeat(60));
    console.log('  SyRide HAMMER TEST — Breaking Point Finder');
    console.log('  No think time. Write-heavy. Ramps to 1200 VUs.');
    console.log('  70% booking writes (bypasses Redis cache)');
    console.log('  30% search reads (coordinate jitter defeats cache)');
    console.log('  Watch MySQL CPU — when it hits 80%, that is your wall.');
    console.log('='.repeat(60) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error('Server unreachable');
    console.log(`✅ Server alive (HTTP ${r.status}). Hammering begins.\n`);
}

export function teardown() {
    console.log('\nKEY METRICS TO REPORT:');
    console.log('  VU count where p95 booking_write_ms > 500ms = soft limit');
    console.log('  VU count where real_5xx_errors > 1%          = hard limit');
    console.log('  Container CPU at breaking point               = bottleneck ID');
}
