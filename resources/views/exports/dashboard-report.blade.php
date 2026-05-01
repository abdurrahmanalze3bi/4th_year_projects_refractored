<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>SyRide Dashboard Report</title>
    <style>
        /* ── Base ──────────────────────────────────────────────────────────── */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1a1a2e;
            background: #fff;
            line-height: 1.5;
        }

        /* ── Page header ───────────────────────────────────────────────────── */
        .page-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
            color: #fff;
            padding: 24px 32px;
            margin-bottom: 24px;
            border-radius: 6px;
        }
        .page-header .brand   { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
        .page-header .sub     { font-size: 12px; opacity: .75; margin-top: 2px; }
        .page-header .meta    { font-size: 10px; opacity: .6; margin-top: 8px; }
        .page-header .accent  { color: #e94560; font-weight: 700; }

        /* ── Section headings ──────────────────────────────────────────────── */
        .section {
            margin-bottom: 28px;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f3460;
            border-left: 4px solid #e94560;
            padding-left: 10px;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        /* ── KPI Cards ─────────────────────────────────────────────────────── */
        .kpi-grid {
            display: table;
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
        }
        .kpi-row { display: table-row; }
        .kpi-card {
            display: table-cell;
            width: 16.6%;
            background: #f8f9ff;
            border: 1px solid #e8eaf0;
            border-radius: 6px;
            padding: 14px 12px;
            text-align: center;
            vertical-align: middle;
        }
        .kpi-card.highlight {
            background: #0f3460;
            border-color: #0f3460;
            color: #fff;
        }
        .kpi-card .kpi-label {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 6px;
        }
        .kpi-card.highlight .kpi-label { color: rgba(255,255,255,.7); }
        .kpi-card .kpi-value {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
        }
        .kpi-card.highlight .kpi-value { color: #fff; }
        .kpi-card .kpi-sub {
            font-size: 9px;
            color: #9ca3af;
            margin-top: 3px;
        }
        .kpi-card.highlight .kpi-sub { color: rgba(255,255,255,.6); }
        .kpi-card .badge-live {
            display: inline-block;
            background: #10b981;
            color: #fff;
            font-size: 8px;
            padding: 1px 6px;
            border-radius: 10px;
            margin-top: 2px;
        }
        .kpi-card .badge-warning {
            display: inline-block;
            background: #f59e0b;
            color: #fff;
            font-size: 8px;
            padding: 1px 6px;
            border-radius: 10px;
            margin-top: 2px;
        }

        /* ── Financial Grid ────────────────────────────────────────────────── */
        .fin-grid {
            display: table;
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
        }
        .fin-row  { display: table-row; }
        .fin-cell {
            display: table-cell;
            width: 50%;
            border: 1px solid #e8eaf0;
            border-radius: 6px;
            padding: 16px;
            vertical-align: top;
        }
        .fin-cell .wallet-name {
            font-size: 11px;
            font-weight: 700;
            color: #0f3460;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e8eaf0;
        }
        .fin-line {
            display: table;
            width: 100%;
            margin-bottom: 5px;
        }
        .fin-line .fl-label {
            display: table-cell;
            font-size: 10px;
            color: #6b7280;
        }
        .fin-line .fl-value {
            display: table-cell;
            text-align: right;
            font-size: 10px;
            font-weight: 600;
            color: #1a1a2e;
        }
        .fin-line .fl-value.positive { color: #10b981; }
        .fin-line .fl-value.negative { color: #e94560; }
        .fin-line .fl-value.balance  { color: #0f3460; font-size: 12px; }

        /* ── Growth Chart (text-based bars) ────────────────────────────────── */
        .chart-table {
            width: 100%;
            border-collapse: collapse;
        }
        .chart-table th {
            font-size: 9px;
            color: #6b7280;
            font-weight: 600;
            text-align: center;
            padding: 4px 6px;
            border-bottom: 1px solid #e8eaf0;
            text-transform: uppercase;
        }
        .chart-table td {
            padding: 6px;
            text-align: center;
            font-size: 10px;
            border-bottom: 1px solid #f3f4f6;
        }
        .bar-wrap {
            background: #f3f4f6;
            border-radius: 3px;
            height: 10px;
            width: 100%;
            margin: 2px auto;
        }
        .bar-fill-trips {
            background: #0f3460;
            height: 10px;
            border-radius: 3px;
        }
        .bar-fill-users {
            background: #e94560;
            height: 10px;
            border-radius: 3px;
        }

        /* ── City Distribution ─────────────────────────────────────────────── */
        .city-table {
            width: 100%;
            border-collapse: collapse;
        }
        .city-table td {
            padding: 7px 4px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .city-name  { font-size: 11px; font-weight: 600; width: 25%; }
        .city-bar-cell { width: 55%; padding: 0 8px; }
        .city-bar-wrap {
            background: #f3f4f6;
            border-radius: 3px;
            height: 8px;
        }
        .city-bar-fill {
            background: linear-gradient(90deg, #0f3460, #e94560);
            height: 8px;
            border-radius: 3px;
        }
        .city-pct  { font-size: 10px; font-weight: 700; color: #0f3460; width: 10%; text-align: right; }
        .city-count{ font-size: 10px; color: #9ca3af; width: 10%; text-align: right; }

        /* ── Recent Activities Table ────────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        .data-table thead tr {
            background: #0f3460;
            color: #fff;
        }
        .data-table thead th {
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .data-table tbody tr:nth-child(even) { background: #f8f9ff; }
        .data-table tbody tr:nth-child(odd)  { background: #fff; }
        .data-table tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #f0f0f8;
            vertical-align: middle;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-completed    { background: #d1fae5; color: #065f46; }
        .status-confirmed    { background: #dbeafe; color: #1e40af; }
        .status-pending      { background: #fef3c7; color: #92400e; }
        .status-cancelled    { background: #fee2e2; color: #991b1b; }
        .status-no_show      { background: #f3e8ff; color: #6b21a8; }

        /* ── Page footer ───────────────────────────────────────────────────── */
        .page-footer {
            margin-top: 32px;
            padding-top: 12px;
            border-top: 1px solid #e8eaf0;
            display: table;
            width: 100%;
        }
        .page-footer .left  {
            display: table-cell;
            font-size: 9px;
            color: #9ca3af;
        }
        .page-footer .right {
            display: table-cell;
            text-align: right;
            font-size: 9px;
            color: #9ca3af;
        }
        .page-footer .right strong { color: #e94560; }

        /* ── Divider ───────────────────────────────────────────────────────── */
        .divider {
            border: none;
            border-top: 1px solid #e8eaf0;
            margin: 20px 0;
        }

        /* ── Utility ───────────────────────────────────────────────────────── */
        .text-right  { text-align: right; }
        .text-center { text-align: center; }
        .font-bold   { font-weight: 700; }
        .color-green { color: #10b981; }
        .color-red   { color: #e94560; }
        .color-blue  { color: #0f3460; }
        .no-data {
            text-align: center;
            color: #9ca3af;
            font-style: italic;
            padding: 20px;
        }
    </style>
</head>
<body>

{{-- ═══════════════════════ PAGE HEADER ═══════════════════════ --}}
<div class="page-header">
    <div class="brand">🚗 SyRide <span class="accent">Admin Report</span></div>
    <div class="sub">General Dashboard Export</div>
    <div class="meta">
        Generated: {{ $generatedAt }} &nbsp;|&nbsp;
        Period: <strong>{{ $dateRange['start'] }}</strong> → <strong>{{ $dateRange['end'] }}</strong>
    </div>
</div>

{{-- ═══════════════════════ KPI STATS ═══════════════════════ --}}
@if($stats && in_array('stats', $sections))
    <div class="section">
        <div class="section-title">Platform Overview</div>
        <div class="kpi-grid">
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-label">Total Users</div>
                    <div class="kpi-value">{{ number_format($stats['total_users']) }}</div>
                    <div class="kpi-sub">Registered</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Active Trips</div>
                    <div class="kpi-value">{{ number_format($stats['active_trips']) }}</div>
                    <div class="kpi-sub"><span class="badge-live">LIVE</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Completed Trips</div>
                    <div class="kpi-value">{{ number_format($stats['completed_trips']) }}</div>
                    <div class="kpi-sub">All time</div>
                </div>
                <div class="kpi-card highlight">
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-value">{{ $stats['total_revenue']['formatted'] }}</div>
                    <div class="kpi-sub">Platform earnings</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Pending Complaints</div>
                    <div class="kpi-value color-red">{{ $stats['pending_complaints'] }}</div>
                    @if($stats['pending_complaints'] > 0)
                        <div class="kpi-sub"><span class="badge-warning">Action needed</span></div>
                    @else
                        <div class="kpi-sub">None</div>
                    @endif
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Verification Requests</div>
                    <div class="kpi-value color-blue">{{ $stats['verification_requests'] }}</div>
                    <div class="kpi-sub">Pending review</div>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- ═══════════════════════ FINANCIAL STATS ═══════════════════════ --}}
@if($financial && in_array('financial', $sections))
    <div class="section">
        <div class="section-title">Financial Summary</div>
        <div class="fin-grid">
            <div class="fin-row">
                {{-- SyCash --}}
                @if(isset($financial['sycash']))
                    <div class="fin-cell">
                        <div class="wallet-name">💳 SyCash Wallet (Escrow)</div>
                        <div class="fin-line">
                            <span class="fl-label">Current Balance</span>
                            <span class="fl-value balance">{{ $financial['sycash']['current_balance'] }}</span>
                        </div>
                        @if(isset($financial['sycash']['total_escrow_in']))
                            <div class="fin-line">
                                <span class="fl-label">Total Escrow In</span>
                                <span class="fl-value positive">{{ $financial['sycash']['total_escrow_in'] }}</span>
                            </div>
                            <div class="fin-line">
                                <span class="fl-label">Total Escrow Out</span>
                                <span class="fl-value negative">{{ $financial['sycash']['total_escrow_out'] }}</span>
                            </div>
                            <div class="fin-line">
                                <span class="fl-label">Total Refunds Paid</span>
                                <span class="fl-value negative">{{ $financial['sycash']['total_refunds_paid'] }}</span>
                            </div>
                        @elseif(isset($financial['sycash']['total_creation_fees']))
                            {{-- Fallback for old-model data --}}
                            <div class="fin-line">
                                <span class="fl-label">Total Creation Fees</span>
                                <span class="fl-value positive">{{ $financial['sycash']['total_creation_fees'] }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Primary Admin --}}
                @if(isset($financial['primary_admin']))
                    <div class="fin-cell">
                        <div class="wallet-name">🏦 Primary Admin Wallet</div>
                        <div class="fin-line">
                            <span class="fl-label">Current Balance</span>
                            <span class="fl-value balance">{{ $financial['primary_admin']['current_balance'] }}</span>
                        </div>
                        @if(isset($financial['primary_admin']['total_platform_fees']))
                            <div class="fin-line">
                                <span class="fl-label">Total Platform Fees (5%)</span>
                                <span class="fl-value positive">{{ $financial['primary_admin']['total_platform_fees'] }}</span>
                            </div>
                        @else
                            <div class="fin-line">
                                <span class="fl-label">Total Collected</span>
                                <span class="fl-value positive">{{ $financial['primary_admin']['total_collected'] ?? '0.00 SYP' }}</span>
                            </div>
                            <div class="fin-line">
                                <span class="fl-label">Total Disbursed</span>
                                <span class="fl-value negative">{{ $financial['primary_admin']['total_disbursed'] ?? '0.00 SYP' }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if(isset($financial['active_rides_locked']))
            <div style="margin-top:8px; padding:10px 14px; background:#fff8e1; border:1px solid #fde68a; border-radius:6px; font-size:10px;">
                🔒 <strong>Active Rides Locked in Escrow:</strong>
                <span style="float:right; font-weight:700; color:#0f3460;">{{ $financial['active_rides_locked'] }}</span>
            </div>
        @endif
    </div>
@endif

{{-- ═══════════════════════ GROWTH CHART ═══════════════════════ --}}
@if($growth && in_array('growth', $sections))
    <div class="section">
        <div class="section-title">User Growth &amp; Activity — Last 6 Months</div>
        @php
            $maxTrips = collect($growth['data'])->max('completed_trips') ?: 1;
            $maxUsers = collect($growth['data'])->max('new_users') ?: 1;
        @endphp
        <table class="chart-table">
            <thead>
            <tr>
                <th>Month</th>
                <th colspan="2">Completed Trips</th>
                <th>Count</th>
                <th colspan="2">New Users</th>
                <th>Count</th>
            </tr>
            </thead>
            <tbody>
            @foreach($growth['data'] as $row)
                <tr>
                    <td class="font-bold">{{ $row['label'] }}</td>
                    <td colspan="2">
                        <div class="bar-wrap">
                            <div class="bar-fill-trips" style="width:{{ min(100, ($row['completed_trips'] / $maxTrips) * 100) }}%"></div>
                        </div>
                    </td>
                    <td class="font-bold color-blue">{{ number_format($row['completed_trips']) }}</td>
                    <td colspan="2">
                        <div class="bar-wrap">
                            <div class="bar-fill-users" style="width:{{ min(100, ($row['new_users'] / $maxUsers) * 100) }}%"></div>
                        </div>
                    </td>
                    <td class="font-bold color-red">{{ number_format($row['new_users']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="margin-top:8px; font-size:9px; color:#9ca3af;">
            <span style="display:inline-block; width:10px; height:10px; background:#0f3460; border-radius:2px; margin-right:4px;"></span>Completed Trips &nbsp;&nbsp;
            <span style="display:inline-block; width:10px; height:10px; background:#e94560; border-radius:2px; margin-right:4px;"></span>New Users
        </div>
    </div>
@endif

{{-- ═══════════════════════ CITY DISTRIBUTION ═══════════════════════ --}}
@if($cities && in_array('cities', $sections))
    <div class="section">
        <div class="section-title">Trip Distribution by City</div>
        @if(count($cities) > 0)
            <table class="city-table">
                @foreach($cities as $city)
                    <tr>
                        <td class="city-name">{{ $city['city_en'] }}</td>
                        <td class="city-bar-cell">
                            <div class="city-bar-wrap">
                                <div class="city-bar-fill" style="width:{{ $city['percentage'] }}%"></div>
                            </div>
                        </td>
                        <td class="city-pct">{{ $city['percentage'] }}%</td>
                        <td class="city-count">{{ number_format($city['count']) }} users</td>
                    </tr>
                @endforeach
            </table>
        @else
            <div class="no-data">No city data available for the selected period.</div>
        @endif
    </div>
@endif

{{-- ═══════════════════════ RECENT ACTIVITIES ═══════════════════════ --}}
@if($recent && in_array('recent', $sections))
    <div class="section" style="page-break-before: always;">
        <div class="section-title">Recent Booking Activities</div>
        @if(count($recent) > 0)
            <table class="data-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Passenger</th>
                    <th>Driver</th>
                    <th>Route</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-right">Value</th>
                </tr>
                </thead>
                <tbody>
                @foreach($recent as $activity)
                    <tr>
                        <td class="color-blue font-bold">{{ $activity['booking_id'] }}</td>
                        <td>
                            <strong>{{ $activity['user']['name'] }}</strong>
                            <br><span style="color:#9ca3af; font-size:8.5px;">{{ $activity['user']['number'] }}</span>
                        </td>
                        <td>{{ $activity['driver'] }}</td>
                        <td style="font-size:9px; max-width:120px; word-break:break-word;">{{ $activity['route'] }}</td>
                        <td style="white-space:nowrap;">{{ $activity['date']['human'] }}</td>
                        <td>
                            @php
                                $statusClass = match($activity['status']) {
                                    'completed'  => 'status-completed',
                                    'confirmed'  => 'status-confirmed',
                                    'pending'    => 'status-pending',
                                    'cancelled'  => 'status-cancelled',
                                    'no_show'    => 'status-no_show',
                                    default      => 'status-pending',
                                };
                            @endphp
                            <span class="status-badge {{ $statusClass }}">{{ $activity['status'] }}</span>
                        </td>
                        <td class="text-right font-bold">{{ $activity['value'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <div class="no-data">No booking activity found for the selected period.</div>
        @endif
    </div>
@endif

{{-- ═══════════════════════ PAGE FOOTER ═══════════════════════ --}}
<div class="page-footer">
    <div class="left">
        SyRide Platform &mdash; Confidential Admin Report<br>
        This document was auto-generated and is intended for authorised personnel only.
    </div>
    <div class="right">
        Generated by <strong>SyRide</strong> Admin System<br>
        <strong>{{ $generatedAt }}</strong>
    </div>
</div>

</body>
</html>
