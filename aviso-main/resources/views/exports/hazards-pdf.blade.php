<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Hazard Logs Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th {
            background-color: #f4f4f4;
            text-align: left;
            padding: 8px;
        }
        td {
            padding: 8px;
        }
        .text-center {
            text-align: center;
        }
        .date {
            text-align: right;
            font-size: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

    <h2>Hazard Logs Report</h2>
    <div class="date">Generated on: {{ now()->format('Y-m-d H:i:s') }}</div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Type</th>
                <th>Area</th>
                <th>Confidence</th>
                <th>Distance</th>
                <th>Status</th>
                <th>Detected At</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->haz_code }}</td>
                    <td>{{ $log->type }}</td>
                    <td>{{ $log->area }}</td>
                    <td>{{ $log->confidence }}%</td>
                    <td>{{ $log->distance }}m</td>
                    <td>{{ ucfirst($log->status) }}</td>
                    <td>{{ $log->detected_at ? $log->detected_at->format('Y-m-d H:i') : '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center">No hazard logs found for the applied filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
