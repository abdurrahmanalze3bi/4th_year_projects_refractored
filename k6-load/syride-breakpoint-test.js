/**
 * SyRide — Breakpoint Test (Extended)
 * 50 passenger tokens + 16 driver tokens.
 *
 * TOKEN NOTE:
 *   throttle:api  = 60 req/min per token
 *   With avg 4s think time, each VU makes ~15 req/min
 *   Safe VU ceiling without 429s = 50 tokens × (60/15) = 200 VUs
 *   Above 200 VUs you'll see 429s — test handles them (not counted as failures)
 *
 * HOW TO RUN:
 *   k6 run "k6-load\syride-breakpoint-test.js"
 *
 * WATCH IN SECOND TERMINAL while running:
 *   docker stats --format "table {{.Name}}`t{{.CPUPerc}}`t{{.MemUsage}}"
 */

import http from 'k6/http';
import { sleep, check, group } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { randomIntBetween } from './k6-utils.js';
http.setResponseCallback(http.expectedStatuses({min: 200, max: 299}, 400, 401, 404, 409, 422));
// ─── CONFIG ───────────────────────────────────────────────────────────────────

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

// ─── TOKENS (fresh — expire in 10 hours from generation) ─────────────────────

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

const DRIVER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MSwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6IjQ4YjNjOTdiLTM1MjYtNDc3ZC05MWM3LTliZjA1MTYzOGY0OCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.HofDA4GlQG2NOa46CKC79dFROFu1FeJCHa3hIxZqtHw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MiwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6ImRhYzljYmM3LTAzNjgtNDFhYy05Y2UzLTZkOTgxZmJjOWM0ZiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.YcV4WWbMHxgYeZ2mDoUlYkn5_tf_4CjP8BCguw78zhc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MywiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6IjE0NWM0ODQ0LWQ4MDAtNDQyMS1hZTgxLWRkNjdjYTUxMWE5MSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.dCADi4IZTWWYszubRD_xq79eU3k4Igu3u6QGWA_URIU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NCwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6IjhhN2I3MTAxLWI5ZjYtNDUzMS1hYTU0LTEzNzA1YzZjNjAwYiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.5Pe4W_8WdNjEKzg-T0IstP3Mdp2m7aKZYMZ8oKR0RdE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NSwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6IjMzYjUyM2Y2LTlkZWEtNDMyYS1hOTZkLTFhODcxNzBkYjU2ZSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.9uquwnssqQkud16Zk3Heomaj0kiSqL8b4_1nIKjjMqA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NiwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6IjVmYzQyYjkzLTkzZDEtNDgxNS1iYjkyLTNkMDE2Y2UwYTNmMCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.2nDAbkD8eHfFHulTy5WFAGDDxe7KHzbsfID1uwE-e2g',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NywiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6ImI0OGE3OTVmLTFiMjMtNDZhMS1iMjVlLTIwOWIwNmYwMGQxOCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.shsGxYbjxNqv6vl3vg6GdDScmVKQTTw3i3s8EKbXS5M',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6OCwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6IjM0OGI4YzZhLTgzNjEtNGVmZS04MjY4LTEyMmVmMTk2NzFlMCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.jwpewrKHVRwvYrCHShNGJnQItrJKyUV8BmA6pjF2tKk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6OSwiaWF0IjoxNzg2NjcwNDExLCJleHAiOjE3ODY3MDY0MTEsImp0aSI6ImFmZWYwOWIxLTZhMGQtNDkwNC05ZDNjLWVkZmFiYmZhMjljNSIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.-c3TtTWzNiGwxMoz7_ivid_tP0umkEYfr_N48bWzqJg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTAsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiI4OGIzMzQyMC1jNjE5LTQxNTEtYTdjNS1jYjI1MDBlZGUyNDIiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.EyuT1ScNllA1OsyzXnIb34zY4LPhz77frLuVU7NB2ps',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTEsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiJhNzU2ZDM2NC00OTE5LTRmZGMtODg0NS1mN2U0NjQ2OTc5ZDkiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.6S6PFhSpqkcm_IWr745qb6x5jQGoNcYZS-SoWaic0fs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTIsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiI4OTVkOWU0OS00ZWY1LTQxYjQtYTVjMy0xNmJiNjRiMjMxNTYiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.mvM9LGsB_mh3N3OIgROdp4WtS9qvmyuLsyRWRzJFe2c',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTMsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiIxNjE5NmVhMy04OGEwLTRjZGItOWQ5OC0yZTFiNmQxZjhkZDEiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.MivmnJNtEk_6xXwsTCrKOg1yG9NA9GpakS1V7gXbonI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTQsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiJhZjlmNGJmYi02NWFiLTQzNmEtYWU4Yi05MDdkMDY1MzA5MWYiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.5bJGphrJb8vqLzfAj9k1n3u6JUCbde7HFNTWL6zsy30',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTUsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiIyOGEzZmUxNy04YTA0LTRiMGEtODg4NC01MDU4YTdhYjZkYTkiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.83FDl5Yx3mPpJaeDTqLOqGr_Sf31bQFhEo8cOB5fvFs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTYsImlhdCI6MTc4NjY3MDQxMSwiZXhwIjoxNzg2NzA2NDExLCJqdGkiOiIzMWViNmU3NS0zNTE1LTQ2MTMtODMzOS0yMjgyMTg2YzBmNjEiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.96ZKeavhv5iHFQaE9BWLq5vVcNGZk63oRf_ev-HjOc4',
];

// Real IDs from your DB — update with output of:
// docker exec syride_mysql mysql -uroot -psecret 4th_year_project_db -e "SELECT id FROM rides WHERE status='active' LIMIT 20;"
const RIDE_IDS = [2, 3, 4, 6, 8, 11, 14, 15, 17, 18, 20, 22, 23, 29, 31, 32, 42, 43, 46, 48];

// All passenger user IDs
const USER_IDS = [
    251, 252, 253, 254, 255, 256, 257, 258, 259, 260,
    261, 262, 263, 264, 265, 266, 267, 268, 269, 270,
    271, 272, 273, 274, 275, 276, 277, 278, 279, 280,
    281, 282, 283, 284, 285, 286, 287, 288, 289, 290,
    291, 292, 293, 294, 295, 296, 297, 298, 299, 300,
];

// Amman coordinates
const LOCATIONS = [
    { lat: 31.9539, lng: 35.9106, name: 'Abdali' },
    { lat: 31.9784, lng: 35.8594, name: 'Mecca Street' },
    { lat: 31.9454, lng: 35.9284, name: 'Sweifieh' },
    { lat: 31.9037, lng: 35.9383, name: 'Airport Road' },
    { lat: 32.0156, lng: 35.8621, name: 'Zarqa Rd' },
];

// ─── CUSTOM METRICS ───────────────────────────────────────────────────────────

const errorRate         = new Rate('real_errors');
const searchTime        = new Trend('ride_search_ms', true);
const bookTime          = new Trend('booking_ms', true);
const statusPollTime    = new Trend('status_poll_ms', true);
const walletTime        = new Trend('wallet_ms', true);
const throttledRequests = new Counter('throttled_429s');

// ─── STAGES ───────────────────────────────────────────────────────────────────
// 50 tokens × (60 req/min ÷ 15 req/min per VU) = 200 VU safe ceiling
// Above 200 VUs: 429s appear — excluded from real_errors
// Ramp to 500 to find the actual hardware/DB breaking point

export const options = {
    stages: [
        { duration: '1m',  target: 100 }, // already proven stable — baseline
        { duration: '3m',  target: 200 }, // safe limit — no throttling
        { duration: '3m',  target: 300 }, // first stress zone — watch MySQL CPU
        { duration: '3m',  target: 400 }, // heavy stress — p95 should climb here
        { duration: '3m',  target: 500 }, // find the wall
        { duration: '2m',  target: 0   }, // cool down — does latency recover?
    ],

    thresholds: {
        'http_req_duration': ['p(95)<2000'],  // relaxed — we WANT to find where it breaks
        'real_errors':       ['rate<0.05'],   // allow up to 5% — flag when exceeded
        'ride_search_ms':    ['p(95)<1000'],
        'booking_ms':        ['p(95)<2000'],
        'status_poll_ms':    ['p(95)<500'],
    },
};

// ─── HELPERS ──────────────────────────────────────────────────────────────────

function auth(token) {
    return {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type':  'application/json',
            'Accept':        'application/json',
        },
    };
}

function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

function record(r, label) {
    if (r.status === 429) {
        throttledRequests.add(1);
        return;
    }
    const ok = r.status > 0 && r.status < 500;
    errorRate.add(ok ? 0 : 1);
    if (!ok) {
        console.error(`❌ ${label}: HTTP ${r.status} (${r.timings.duration.toFixed(0)}ms)`);
    }
}

// ─── FLOWS ────────────────────────────────────────────────────────────────────

function passengerSearchAndBookFlow() {
    const token  = pick(PASSENGER_TOKENS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS.filter(l => l.name !== origin.name));

    group('search', () => {
        const t0 = Date.now();
        const r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?pickup_lat=${origin.lat}&pickup_lng=${origin.lng}` +
            `&destination_lat=${dest.lat}&destination_lng=${dest.lng}&seats=1`,
            { ...auth(token), tags: { name: 'ride_search' } }
        );
        searchTime.add(Date.now() - t0);
        check(r, {
            'search not 500': (r) => r.status !== 500,
            'search not 404': (r) => r.status !== 404,
        });
        record(r, 'ride search');
    });

    sleep(randomIntBetween(3, 6));

    if (Math.random() < 0.5) {
        const rideId = pick(RIDE_IDS);
        group('book', () => {
            const t0 = Date.now();
            const r = http.post(
                `${BASE_URL}/api/rides/${rideId}/book`,
                JSON.stringify({ seats: 1, pickup_lat: origin.lat, pickup_lng: origin.lng }),
                { ...auth(token), tags: { name: 'book_ride' } }
            );
            bookTime.add(Date.now() - t0);
            check(r, {
                'book not 500': (r) => r.status !== 500,
                'book not 404': (r) => r.status !== 404,
            });
            record(r, 'book ride');
        });
    }

    sleep(randomIntBetween(1, 3));
}

function statusPollingFlow() {
    const token  = pick(PASSENGER_TOKENS);
    const rideId = pick(RIDE_IDS);

    group('poll', () => {
        const t0 = Date.now();
        const r = http.get(
            `${BASE_URL}/api/rides/${rideId}`,
            { ...auth(token), tags: { name: 'ride_status' } }
        );
        statusPollTime.add(Date.now() - t0);
        check(r, {
            'poll not 500':  (r) => r.status !== 500,
            'poll < 500ms':  (r) => r.timings.duration < 500,
        });
        record(r, 'status poll');
    });

    sleep(randomIntBetween(3, 5));

    group('notif badge', () => {
        const r = http.get(
            `${BASE_URL}/api/notifications/unread-count`,
            { ...auth(token), tags: { name: 'notif_count' } }
        );
        check(r, { 'badge not 500': (r) => r.status !== 500 });
        record(r, 'notif badge');
    });

    sleep(randomIntBetween(2, 4));
}

function appOpenFlow() {
    const token  = pick(PASSENGER_TOKENS);
    const userId = pick(USER_IDS);

    group('score', () => {
        const r = http.get(`${BASE_URL}/api/score`, { ...auth(token), tags: { name: 'score' } });
        check(r, { 'score not 500': (r) => r.status !== 500 });
        record(r, 'score');
    });

    group('wallet', () => {
        const t0 = Date.now();
        const r = http.get(`${BASE_URL}/api/wallet/balance`, { ...auth(token), tags: { name: 'wallet' } });
        walletTime.add(Date.now() - t0);
        check(r, {
            'wallet not 500': (r) => r.status !== 500,
            'wallet not 404': (r) => r.status !== 404,
        });
        record(r, 'wallet balance');
    });

    group('profile', () => {
        const r = http.get(`${BASE_URL}/api/profile/${userId}`, { ...auth(token), tags: { name: 'profile' } });
        check(r, {
            'profile not 500': (r) => r.status !== 500,
            'profile not 404': (r) => r.status !== 404,
        });
        record(r, 'profile');
    });

    sleep(randomIntBetween(3, 8));
}

function driverFlow() {
    const token  = pick(DRIVER_TOKENS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS.filter(l => l.name !== origin.name));

    group('create ride', () => {
        const r = http.post(
            `${BASE_URL}/api/rides/create-with-route`,
            JSON.stringify({
                origin_lat:      origin.lat,
                origin_lng:      origin.lng,
                destination_lat: dest.lat,
                destination_lng: dest.lng,
                available_seats: randomIntBetween(1, 4),
                departure_time:  new Date(Date.now() + 3600000).toISOString(),
                price_per_seat:  randomIntBetween(3, 10),
            }),
            { ...auth(token), tags: { name: 'create_ride' } }
        );
        check(r, { 'create not 500': (r) => r.status !== 500 });
        record(r, 'create ride');
    });

    sleep(randomIntBetween(5, 12));

    group('my bookings', () => {
        const r = http.get(`${BASE_URL}/api/bookings`, { ...auth(token), tags: { name: 'my_bookings' } });
        check(r, { 'bookings not 500': (r) => r.status !== 500 });
        record(r, 'my bookings');
    });

    sleep(randomIntBetween(3, 6));
}

function authFlow() {
    group('otp send', () => {
        const r = http.post(
            `${BASE_URL}/api/otp/send`,
            JSON.stringify({ phone: `+96279${randomIntBetween(1000000, 9999999)}` }),
            { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, tags: { name: 'otp_send' } }
        );
        check(r, {
            'otp not 500': (r) => r.status !== 500,
            'otp not 404': (r) => r.status !== 404,
        });
        record(r, 'otp send');
    });
    sleep(randomIntBetween(3, 8));
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────

export default function () {
    const rand = Math.random();
    if      (rand < 0.40) passengerSearchAndBookFlow();
    else if (rand < 0.65) statusPollingFlow();
    else if (rand < 0.85) appOpenFlow();
    else if (rand < 0.95) driverFlow();
    else                  authFlow();
}

// ─── SETUP ────────────────────────────────────────────────────────────────────

export function setup() {
    console.log('\n' + '='.repeat(60));
    console.log('  SyRide Extended Breakpoint Test');
    console.log(`  Target: ${BASE_URL}`);
    console.log(`  Tokens: ${PASSENGER_TOKENS.length} passenger, ${DRIVER_TOKENS.length} driver`);
    console.log(`  Rides:  ${RIDE_IDS.join(', ')}`);
    console.log('  Stages: 100 → 500 VUs over ~15 minutes');
    console.log('  Safe ceiling (no 429s): ~200 VUs');
    console.log('  Goal: find VU count where p95 > 500ms or errors > 1%');
    console.log('='.repeat(60) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) {
        throw new Error(`Cannot reach ${BASE_URL} — is Docker running?`);
    }
    console.log(`✅ Server alive (HTTP ${r.status}). Starting ramp-up.\n`);
}

// ─── TEARDOWN ─────────────────────────────────────────────────────────────────

export function teardown() {
    console.log('\n' + '='.repeat(60));
    console.log('HOW TO READ THESE RESULTS:');
    console.log('─'.repeat(60));
    console.log('real_errors rate > 1%     → that VU count = your hard limit');
    console.log('p95 crosses 500ms         → degradation point (soft limit)');
    console.log('p95 crosses 2000ms        → unacceptable for mobile app');
    console.log('throttled_429s is high    → expected above 200 VUs, not a failure');
    console.log('MySQL CPU > 80%           → DB is the bottleneck, not PHP');
    console.log('app CPU > 80%             → PHP workers saturated');
    console.log('─'.repeat(60));
    console.log('REAL USER EQUIVALENT (Little\'s Law):');
    console.log('  RPS = VUs × (1 / avg_response_sec)');
    console.log('  Active users ≈ RPS × avg_session_duration_sec');
    console.log('='.repeat(60) + '\n');
}
