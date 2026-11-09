<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $filename }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef5fb;
            color: #163a5f;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .toolbar {
            position: sticky;
            top: 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid #d7e8f6;
        }
        .toolbar button {
            height: 38px;
            border: 0;
            border-radius: 10px;
            padding: 0 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .print { background: #1b7ad8; color: #fff; }
        .close { background: #e8f4fc; color: #1b7ad8; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            background: #fff;
            padding: 22mm 16mm;
            box-shadow: 0 10px 30px rgba(15, 76, 138, 0.12);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #1b7ad8;
            padding-bottom: 14px;
        }
        .brand img { width: 56px; height: 56px; object-fit: contain; }
        h1 { margin: 0; font-size: 22px; color: #0f4c8a; }
        .sub { margin: 4px 0 0; color: #5b7c99; font-size: 13px; }
        h2 { margin: 22px 0 10px; font-size: 18px; color: #0f4c8a; }
        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
            margin-top: 16px;
            font-size: 13px;
        }
        .meta span { color: #7d93a8; display: block; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        th, td { border: 1px solid #d7e8f6; padding: 8px 10px; text-align: left; }
        th { background: #f4f9fd; color: #5b7c99; font-size: 11px; text-transform: uppercase; }
        .note { margin-top: 18px; font-size: 11px; color: #7d93a8; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: auto; margin: 0; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="close" onclick="window.close()">Close</button>
        <button type="button" class="print" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <main class="sheet">
        <div class="brand">
            <img src="{{ asset('images/safesakay-logo.png') }}?v=2" alt="SafeSakay">
            <div>
                <h1>Passenger Manifest — {{ $trip['boat']['name'] }}</h1>
                <p class="sub">{{ $organization }} · Official trip record</p>
            </div>
        </div>

        <div class="meta">
            <div><span>Boat</span><strong>{{ $trip['boat']['name'] }}</strong></div>
            <div><span>Route</span><strong>{{ $trip['direction'] }}</strong></div>
            <div><span>Date</span><strong>{{ $trip['trip_date_label'] }}</strong></div>
            <div><span>Departure Time</span><strong>{{ $trip['departure_time_label'] }}</strong></div>
            <div><span>Operator</span><strong>{{ $trip['boat']['operator_name'] ?: 'Unassigned' }}</strong></div>
            <div><span>Passenger Count</span><strong>{{ $trip['passenger_count'] }} / {{ $trip['boat']['capacity'] }}</strong></div>
        </div>

        <h2>Passenger Details</h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Full Name</th>
                    <th>Age</th>
                    <th>Gender</th>
                    <th>Contact Number</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($trip['passengers'] as $index => $passenger)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $passenger['full_name'] }}</td>
                        <td>{{ $passenger['age'] }}</td>
                        <td>{{ $passenger['gender'] }}</td>
                        <td>{{ $passenger['contact_number'] }}</td>
                        <td>{{ $passenger['remarks'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">No passengers have been recorded for this trip.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <p class="note">
            This manifest is linked to trip {{ $trip['code'] }} and boat {{ $trip['boat']['code'] }}.
            Passenger names and contact numbers are for authorized SafeSakay administrators and official safety records only.
        </p>
    </main>

    @if ($autoPrint)
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>
