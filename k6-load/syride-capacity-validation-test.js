/**
 * SyRide — Capacity Validation: 70% Sustained + 30% Spike Absorption
 */

import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

http.setResponseCallback(http.expectedStatuses(
    { min: 200, max: 299 }, 400, 401, 404, 409, 422
));

// ─── CONFIG ──────────────────────────────────────────────────────────────────

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

// ─── TOKENS — fresh, expire 10h from generation ──────────────────────────────

const PASSENGER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUxLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZjgwOTQ1NTQtMjA4Yy00NTFjLThhODctMjliOGNmNjhmNGJjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.8XB63TZazwnJchFFAGaSPt6U5BzGZ3w6U4NlhQ_i6WU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUyLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiODU2ZmZlYzAtNzVlMy00MDU5LWI0N2EtZDE3MWE4YTI0ZWJlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.SfDEBmrLcWXEcZybeDj319aYNYvN595MLdsENwNFh24',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjUzLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZWU4ODQ4ZTEtM2I5YS00MTM2LTlkODItZGZkMmNlOWJlZTM2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ImT6CMWYNzLKhNL-geFKXgJ50DQi-bFH8hW1EFBw5X4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU0LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYjc5MTQ0NGEtY2Q4MC00MGMxLTg4ZjQtZmYzNDU4MGI5ZWY1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ptDbPUSWZveGuk0pWvJ8ZLk3AmkHZH51M3LNZ-IuJ2Q',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU1LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZGYwZjU2MTItMjdmMS00NmQ3LWI4NjMtZDE5NzVjZjYxYWZhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.7YFeWSrUiCVL8IEbAu7YImZEkW9mjkT9PDGHItoyZ_s',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU2LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiOWEzMTk2MmQtYmM3Zi00YmNlLWFmOGQtNjEwODIyNWU5NGYwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.gQrNLxVUkVuV7FrLvn-hwGbacaPspoLubD7Ryyu3fRM',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU3LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNGQxZWY1NzctZDU3NS00NjE0LWE2MjAtZGQ2NjgwZTI3NTNhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ATk_0CuVX3Yn7dCGpQKZmr0Khn0pnwk6sgCPrZSJRos',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU4LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYTZjZTMwMmItNTA2ZC00Y2MyLWJlNzYtZWYxOGFhZGYxNzQ3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.HaYofkTphw2Aa2tgmNaSDz-PbvT4r44oLcvEiRlU_hA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjU5LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZWJmOTk4OWItMWVhMC00ODc3LWEwODItMjJlZjY3OWMzNjEzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.dwqvJR8DHXbI9I0v7nquhv5QBHVRjqoITmf24aPofWI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYwLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZWUzOTEzM2MtY2I2OS00MzU1LWFmZjQtZGRkN2NmMjg5NDhhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.3SHsvBOCZu9qlPzfANwBit0roM7hSzawCDA2UicxCds',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYxLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZmNjYTBjMzgtY2YxMC00MmIxLTk3MjYtNmU4NDQ0NGYxY2I3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ebj3-O8TDQDagIl2qxmAMXwmdMinQ3RlK96i3om2jds',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYyLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiN2RiNjA0YTItMDY2MC00Zjc5LThiMDUtN2U4YzIzMjYwMTJjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.gTLhyK-UEax-9mdF5dadF36t8vHk0htKv9oEnGKk_4M',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjYzLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNTk2NjdiOTktNmE0Ni00ODg3LWJlNDktMjJlNzBiMDFkYzVlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.KA6oMMItRpZU0YLz_qErbJo5dFaOILfX_0tvUEiUFog',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY0LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMGQ4N2U3MDctNzMxOC00YWU0LWJjM2ItY2M2Mzc2MGVmYjljIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.qbPFxtVcJUNG2fFlEclI7sRLBcCI_BX5K61dkNvWtBA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY1LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYWY1Mjc5YjEtZGE4ZS00MTZkLWFmOTctMzJmOWE1NGIzM2I1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.-YsoRasW7Zl_3dCBBKoYJN6s9j2IulYWZjvonh4Sxq8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY2LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNWY2NWE1YjAtMDQxZS00MzA5LWEwMWMtYWQ1NGI2NDMxMTkxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.grLgZfKGRKUx9XyTZ0tIJuuj5ioHABNGWIQMSU5IGek',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY3LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNjM3ZmIzYTctYWI4OS00ZjA2LWI4YTMtOGQ1NmYyOWM1MzA4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Nb1wQC4ilzM8QLlKl_EwpV0IoYskjyUzYQhxd4vYiWc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY4LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMTg0MWM0NzItMjgyOC00NzA1LTkwNWUtMmRjNDE2NDg0YzFkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Lx7S85R9sUPxaZWTtq_Utld0dXOaGOq3Dnb_TOQc5EI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjY5LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYjJiZTI4NmItMDViYS00NDgwLTk0MjgtYzFiZTRmNDRlYTMwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.TMDx5Rj7NxydEgmezopSBPAfPkCShNhv00KCFtxorh4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcwLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMjdlNWJjOWQtNmJkYy00MGQ4LWFkMzItNDZmMTRkMzQ4N2QwIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.bj5Guq-9qnbgc54Y63BMjLOb05lSSokI-96W8TPviSg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcxLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiM2Y1MmQyNmItOTU2ZS00MzBmLTk2ZTMtNDk4OWUzZGIwOWQyIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.pFDQiI4GSdI_CMulvIfczJ-AqG_oTIZU-XNDKr8ZVp0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjcyLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMzNmMzA0ZTItNWE1NC00MTM0LWIwOTYtNDZkZDBmZTNhZDIzIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0._B_0tGI6edTOhUCg9nTvRSBeul5haMF0EJk0yFXRvk8',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjczLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZTk5YjQ2YTMtNTEzMC00MjMwLWJhOWItYzE1YjY2OGRkNjBjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.RmYdQZvhFTIWFIfTo6X1FAXwB1JbJ6RwAkeB-YPhUkI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc0LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiN2QwOTUxYTktODZmZC00NmZiLWJlNDUtZjAwNDBmYjg0M2I0IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.QMK62UVFeXTcSZJa9nCh1Jd42BP9lbwbf0vsenN6Pps',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc1LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMmFjNGNkYmUtYzVlNy00MjQzLTg0MTctZWU0NjRiZjQ0ODJlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Tb2HWYk8dcQ4h5a1pCxLDA0KJyM7_cSCpA5v-u796Qs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc2LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZjk1ZDNmODYtNzFjOC00Yjk2LTg5MjMtZWQwZDE2ZjBiMjZjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.ZS-0Ma03Bd6wJMTGIZtQGOpO210mZ31mSy8g3ppuh5o',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc3LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYzgyNTQzZGYtN2YxMi00ODNjLTgxNDItNjI2ZTFmZTRmNDg3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Z_ucz7lLa-gUh1UYd5gUDjph2Q9tAR8w1UZBV7GO-3s',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc4LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMmM5OGEyNzktNzVhYS00MTdiLTk4MzQtOTI0ODA0NmI5ZDRiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.sSlMS5EU41D3b3-kqBx2YCmpTwlQqJo5Z5IOIDpx0YU',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjc5LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNmIyMWM4ZjMtZjRmNy00ZDJiLWI2ZGItZjVkYjEwNDE4M2Q3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.kXobwXl6LDJW3OOIzZfhQvL7UUgWx82eQqMJF2Ypy5U',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgwLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYzMzYzhmOTAtYjcxZi00NGYwLTk4YTAtYjU5MTY4YjExN2M5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.gB6ZhG1lLyTD9IofYrG_TmvwIyy2bruOkpEmDsvFrfs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgxLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNDliNzJhYTgtN2JkNy00N2ExLThhNzctN2JiM2EzMWYwNjc4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.nkUNSmY5hl7en37BskVBzf-egYxolSxdQ54SbE4rXTQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgyLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZDVhMTMxNmItOTZjZi00NjZjLWExZDAtOTVlNGZlNGUwODc1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.eU2l1tMPP2mjQOjTceM1OAyJ74E11KngcV6RYoeCiJ4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjgzLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNmIyZjIwOWMtNjdlZC00NWJhLTk0YzQtMDRhYjcwNjQ2ZmRhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.sNegD3u2-dUDRaDWpmvdwrep9Ia5HbxZfep-h_o8gbw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg0LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiOGZmMjg3ZWUtZjFkZC00NzkxLTgwOTItMjQ5ZWMyOTIwYjYxIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.2gDbkxZQHOwsW-0feK0b1fkZNLwOBxQT209ljR075n4',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg1LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMDExYWU5MTQtZDZiMC00NzQzLWJmZTgtZjQ0YWYyNWI0ZWU3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.4yv7mBuYY6-TgtfPPqZPv3JpeoPGyDkouArbolo-kns',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg2LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZDBlODY0ODgtNzRiNy00MTRjLWJjNDctNmMwMmJjYWRhNmJjIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.stpV2vT2fea_qEcUz0JEmZ9BDk1NHW1Q6-HXo16urKQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg3LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZDJmY2JjYjgtZTFjMi00N2FkLTk5MDItM2QxYjc0NmYzNmY1IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.BZxwtLXM0Jg_QPFHZ0AdgVPMWgKz234TpN85OQniYcc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg4LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNGQ5Y2FhNjgtZmMxOC00NDZjLTk2NDUtNzg1ZjU2YWY2YTc3IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.wW5Gsi35NU2HuP3lczWvWOFCs1yJTKLpGPpR1xItQDg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjg5LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZjNlMTY0N2QtOTZkNS00MjA1LWExZGMtNGExYzQ1ODA5MDc4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.qZQcN66nwcpo1I2mrjn1bgcRBnBEaenyMU_bZnkSngE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkwLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZDg5YThkMGMtMmQzOC00NjhhLTg3YWUtYTM2ODZhZDAzMDJlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.68xlNwl3yf9S8rb5MKEe3xlWHoU9Vbx0lndgJ4di7-M',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkxLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiOGFjZGI2NzktYzdhYi00ZGY2LWJlYTItYjZjNTY1ZTI2YTJlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.nQsviq9vWJzCGadk7wiH-JeyXhrjaoaH4tKjPr9gGNA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkyLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZDJiODUwY2MtMWMxNi00MmM1LTg2MjUtYTFkZTZlY2Y2YTc2IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.j7bv3iQrM9E8ae-i5FB2DlgNeJhFQChX-9wjSh_nQNw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MjkzLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNzI3MmNjZTItMmRkYi00OGY0LThkOWUtZTYwMjVlNTQwYzRkIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.AYwiCX4OBqultqnlN738c-DoAnJzAA6ci2hU_IxNndA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk0LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZDllM2Y5YmItNTRkNC00YjYzLTllNWQtNGU4MGZkMjU2ZDhiIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.PuPmYY_GmGM9Axo_kJPLDZ2WaENSqxmQvuwWtraSpmQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk1LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiMjVlZDNhMzUtM2E2MC00NjkyLTlkMWMtYjkwMTYzOTUwZThlIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.X4RnDEh22nJOFfgrwko-AqY00YWA9kZq3GHqc0GJ738',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk2LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNmQ0OWUwMzMtYzUzYS00ODE2LWI0ZDQtOGQ4OWFiMDVkNjljIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.cMDNIr58dttUr7jC9SjcurxxYv6BeWxIeE3kDo2pdDc',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk3LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYmMzZTAwNTktOTA1Mi00YzNjLWI1NGYtMDQyYmJmZmM0MDZmIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.eZsYjtVdMmkDfP2oMwvo8EBA0aQY7fHTR3TTX3QKRng',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk4LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiNzgxMDE0NjktNzA3MC00NjdmLTlmNWItNmYxMjU5NzBhZTY4IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.Tts34MTg8aCy12GqqxKPKKJgQNcjXJo7clarJ9khzkY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6Mjk5LCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiZmExMmUwZjItMGY5ZS00MzMyLTljMWYtZGQ4MWRiZmE0NzNhIiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.w8GNRGLS-ac4EieSuplOqcMJJKwR-c5HBRSoq5o1ETQ',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MzAwLCJpYXQiOjE3ODY3MDQzNTEsImV4cCI6MTc4Njc0MDM1MSwianRpIjoiYTlhMTMzN2EtNjFhNS00YjNhLWI3NTEtYzg5YjdiYjA0OTY5IiwidHlwZSI6ImFjY2VzcyIsInZlciI6MX0.fKe3l4x2JjAUB71X-md3dv6q_TnTjcEoghvFL9q2__M',
];

const DRIVER_TOKENS = [
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MSwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6IjM3YTdjZDg5LTcyNDAtNDVkYi1iNGQ4LWY5NGEzNTYwMmY5ZCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.lYcmfkp7t4_7zaOLV4k-2H9tQhXS4x1h872wJv5iqd0',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MiwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6IjcyZmZiNDI3LTgwYTctNGI2NS1hOTczLTE4ZWMzYWIzZDZjNCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.-KqNaHoQi-juR7ROBGz_nCOmAeeSx6UjF_hJVzcLhpE',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MywiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6ImQyN2QyY2ZjLTQ5OGEtNGIzZC1iMjUxLWU1MTNlNDkyNjFhMCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.cYt7wGPmN-ET5z9AWnLD1ZvHsnoUxo8B1LOCj_HydWs',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NCwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6ImQ5ODU1NmIyLWUyMDktNDU5My1iMTYyLWNhYTMwNmUyYjMwNiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.I4iHE8HdnGSLxnj5kqo5CIebnOrgtdN67Mk7ehGX7Yo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NSwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6ImQ4OGM3NGU5LTMxZjMtNDQwZC05YzRhLWM1NjUwOWVjZjE2YiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.iKqPLU_Lpt0Y2VLiRjokvwEJSu2DzDi_McfAjZTn5QA',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NiwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6ImMxY2Q1Njg4LWNiMzMtNGEwZC1hZDEzLTQzNjA2NjJkYjNkZCIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.aTAReJep4hpzq7qEb34prIpNHstGlZQYDyJqg05Qkfo',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6NywiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6ImUxODZkY2ExLWZhOTAtNDFkNS1hOTIyLWMwOTk2OTk5MmNlYyIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.OiTKsbZmnDVaBMK15vkK_LkTq9-jCcji9d8OTDREmRk',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6OCwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6IjUwMDMyOTFmLWVmYTItNGJkYy04YjdkLTlkMTAxYmJhYjQwYiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.XneXIDl5x4OvJ5ju2ttW4iyFKWdsQrQO_BGKlN6JUAI',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6OSwiaWF0IjoxNzg2NzA0MzUxLCJleHAiOjE3ODY3NDAzNTEsImp0aSI6IjRkMWJjOWE4LTgyNDYtNGE1ZC1hODE3LTYwNjEyODc4NTcyYiIsInR5cGUiOiJhY2Nlc3MiLCJ2ZXIiOjF9.DW2xHmLYSrkf2H3Z4HzC7jRAxrzkMDRqGCPWzQTHiRw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTAsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiI1NzQxNjY4OC1mOTUxLTRmNmQtYTY5ZC0yZjQxMmMwMjI0MzMiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ._QsDLVqPFovxLnk70ehUZq_yiYT0rnEZId5igdntr_g',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTEsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiI3OTRkYWQxMy02ZDU5LTQ4ZDMtOGUxMC0wNWFlMmUyZWI1MjYiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.gI1yql4TzvIZkzBEzlemNNQaFLmalJigHqdLA0ubiro',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTIsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiIyNzk4Y2UzZi1lNzFhLTQxYzEtODMwNy04NjMyMmViMGZjMmMiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.QMv4kjGuC0i2MSnUy0LEouAoZ6AlgZ1RmiTVCZDfR1g',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTMsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiJmNTYyNDFmYS03ZWU2LTRiYzItOGVkZi05OTFhZDRiMWZmNjgiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.O1Mfn9FgMraHb_e9iVnMU0mVzPjm146OJUYPdi9dIcw',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTQsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiJmZjI2MDJhMy1kZWRkLTRkNjEtOGE3Mi0yZDA5NmNlYjY0OGYiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.HIA0aoiIaA7UntIJjUizIZJvKGilcQq6SG2lxsqhtfY',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTUsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiIwYzZlYzcyYi02YzJhLTQwMTktYTUzNS04OTg5Y2JhMjcyNjQiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.EiNzhkp2o59z-p-8j67B6K_vw01fYY2EA_MO3OQOnFg',
    'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpLm9ud2F5cmlkZS5tZSIsInN1YiI6MTYsImlhdCI6MTc4NjcwNDM1MSwiZXhwIjoxNzg2NzQwMzUxLCJqdGkiOiIzNDIyZTBlZi0wODIwLTRjNzItOGQ1Yi0yZDkzMDcxMDAxNTEiLCJ0eXBlIjoiYWNjZXNzIiwidmVyIjoxfQ.VqOJ8CMezj11sa-uZFPTFoY8CmDtsinOwlfMDXSy2YU',
];

// Active rides — refresh if booking fails with 404
const RIDE_IDS = [2, 3, 4, 6, 8, 11, 14, 15, 17, 18, 20, 22, 23, 29, 31, 32, 42, 43, 46, 48];

// Amman coordinate pool
const LOCATIONS = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ─────────────────────────────────────────────────────────────────

const errorRate = new Rate('real_5xx_errors');
const writeTime = new Trend('booking_write_ms', true);
const readTime  = new Trend('search_read_ms',   true);

// ─── OPTIONS ─────────────────────────────────────────────────────────────────

export const options = {
    stages: [
        { duration: '2m',  target: 200 },  // Phase 1: Warm-up
        { duration: '3m',  target: 470 },  // Phase 2: Ramp to 70%
        { duration: '4m',  target: 470 },  // Phase 3: SUSTAINED HOLD ★
        { duration: '2m',  target: 670 },  // Phase 4: SPIKE ★
        { duration: '3m',  target: 470 },  // Phase 5: RECOVERY ★
        { duration: '2m',  target: 0   },  // Phase 6: Cool-down
    ],
    thresholds: {
        'http_req_duration': ['p(95)<5000'],
        'real_5xx_errors':   ['rate<0.03'],
        'booking_write_ms':  ['p(95)<3000'],
        'search_read_ms':    ['p(95)<2000'],
    },
};

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function authHeader(token) {
    return {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type':  'application/json',
            'Accept':        'application/json',
        },
        redirects: 0,
    };
}

function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

// ─── MAIN LOOP ───────────────────────────────────────────────────────────────

export default function () {
    const token  = pick(PASSENGER_TOKENS);
    const origin = pick(LOCATIONS);

    if (Math.random() < 0.70) {
        const rideId = pick(RIDE_IDS);
        const t0     = Date.now();
        const r = http.post(
            `${BASE_URL}/api/rides/${rideId}/book`,
            JSON.stringify({ seats: 1, pickup_lat: origin.lat, pickup_lng: origin.lng }),
            { ...authHeader(token), tags: { name: 'book_ride', operation: 'write' } }
        );
        writeTime.add(Date.now() - t0);
        const is5xx = r.status >= 500 || r.status === 0;
        errorRate.add(is5xx ? 1 : 0);
        check(r, { 'booking not 5xx': r => r.status < 500 && r.status > 0 });
    } else {
        const jitter = (Math.random() - 0.5) * 0.01;
        const dest   = pick(LOCATIONS);
        const t0     = Date.now();
        const r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?pickup_lat=${(origin.lat + jitter).toFixed(6)}` +
            `&pickup_lng=${(origin.lng + jitter).toFixed(6)}` +
            `&destination_lat=${dest.lat}&destination_lng=${dest.lng}&seats=1`,
            { ...authHeader(token), tags: { name: 'ride_search', operation: 'read' } }
        );
        readTime.add(Date.now() - t0);
        const is5xx = r.status >= 500 || r.status === 0;
        errorRate.add(is5xx ? 1 : 0);
        check(r, { 'search not 5xx': r => r.status < 500 && r.status > 0 });
    }
}

// ─── SETUP ───────────────────────────────────────────────────────────────────

export function setup() {
    console.log('\n' + '='.repeat(72));
    console.log('  SyRide CAPACITY VALIDATION — 70% Sustained + 30% Spike');
    console.log('─'.repeat(72));
    console.log('  Phase 1  ( 0– 2m)   200 VUs   Warm-up');
    console.log('  Phase 2  ( 2– 5m)  →470 VUs   Ramp to 70% CPU');
    console.log('★ Phase 3  ( 5– 9m)   470 VUs   SUSTAINED HOLD — read p95 here');
    console.log('★ Phase 4  ( 9–11m)  →670 VUs   SPIKE — 30% headroom consumed');
    console.log('★ Phase 5  (11–14m)  →470 VUs   RECOVERY — elasticity proof');
    console.log('  Phase 6  (14–16m)  →  0 VUs   Cool-down');
    console.log('─'.repeat(72));
    console.log('  SECOND TERMINAL:');
    console.log('  docker stats --no-stream --format "table {{.Name}}\\t{{.CPUPerc}}\\t{{.MemUsage}}"');
    console.log('  Fire at minutes 7, 10, and 13');
    console.log('='.repeat(72) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) {
        throw new Error(`Cannot reach ${BASE_URL} — is Docker running?`);
    }
    console.log(`✅ Server alive (HTTP ${r.status}). 16-minute run starting.\n`);
}

// ─── TEARDOWN ────────────────────────────────────────────────────────────────

export function teardown() {
    console.log('\n' + '='.repeat(72));
    console.log('FILL IN YOUR DEFENSE SENTENCE:');
    console.log('─'.repeat(72));
    console.log('  "At 70% CPU — 470 VUs, ___ms p95 booking latency —');
    console.log('   SyRide serves 7,000 simultaneous users comfortably.');
    console.log('   A 43% spike to 670 VUs was absorbed: p95 climbed to ___ms');
    console.log('   but 5xx errors stayed at ___%. Recovery took ___ seconds."');
    console.log('='.repeat(72) + '\n');
}
