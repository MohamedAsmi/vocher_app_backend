<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Voucher review</title>
    <style>
        :root{font-family:system-ui,sans-serif;color:#17383a;background:#f4f0e8}
        body{margin:0} header{background:#17383a;color:#f4f0e8;padding:1.3rem 4vw;display:flex;justify-content:space-between;align-items:center;gap:1rem}
        main{max-width:1500px;margin:0 auto;padding:2rem 4vw} h1{margin:1rem 0 .5rem}.muted{color:#617071}
        .toolbar{display:flex;gap:.75rem;flex-wrap:wrap;margin:1rem 0}.toolbar input,.toolbar select{padding:.6rem;border:1px solid #b7c3bd;background:#fff}
        .button,button{display:inline-block;padding:.6rem .9rem;background:#e5b567;border:0;color:#17383a;font-weight:700;cursor:pointer;text-decoration:none}.link-button{background:transparent;color:#f4f0e8;padding:0}
        .table-wrap{overflow:auto;background:#fff;border:1px solid #d7ddd6}table{border-collapse:collapse;width:100%;min-width:1250px}th,td{text-align:left;padding:.8rem;border-bottom:1px solid #e5e8e3;vertical-align:top}th{background:#edf1ec;font-size:.82rem;text-transform:uppercase}
        .pill{font-weight:700}.variance{color:#b33d32}.notice{background:#dcefe3;padding:.7rem;margin:1rem 0}
        @media(max-width:700px){header{align-items:flex-start;flex-direction:column}main{padding:1.25rem 3vw}}
    </style>
</head>
<body>
<header><div><strong>Operations dashboard</strong><div style="color:#b8cfca">Voucher review</div></div><form method="post" action="{{ route('dashboard.logout') }}">@csrf<button class="link-button" type="submit">Sign out</button></form></header>
<main>
    <nav><a href="{{ route('dashboard') }}">API health</a> | <a href="{{ route('dashboard.vouchers') }}">Voucher review</a></nav>
    <h1>Voucher review</h1>
    <p class="muted">Filter all manager vouchers by business date and outlet manager.</p>
    <form class="toolbar" method="get">
        <label>Business date <input name="date" type="date" value="{{ $date }}"></label>
        <label>Manager <select name="manager"><option value="">All managers</option>@foreach ($managers as $manager)<option value="{{ $manager }}" @selected(request('manager') === $manager)>{{ $manager }}</option>@endforeach</select></label>
        <button type="submit">Filter</button><a class="button" href="{{ route('dashboard.vouchers') }}">All submissions</a>
    </form>
    <div class="notice">{{ count($vouchers) }} voucher(s) found{{ $date ? ' for '.\Carbon\Carbon::parse($date)->format('d-m-Y') : '' }}.</div>
    <div class="table-wrap"><table><thead><tr><th>Business date</th><th>Organization / outlet</th><th>Outlet manager</th><th>Status</th><th>Submitted by manager</th><th>Bookkeeper review</th><th>Sales</th><th>Cash reconciliation</th><th>Expenses</th><th>Details</th></tr></thead><tbody>
    @forelse ($vouchers as $item)
        @php($voucher = $item['voucher']) @php($submission = $item['submission']) @php($approval = $item['approval'])
        <tr>
            <td>{{ \Carbon\Carbon::createFromFormat('Ymd', $voucher->date_key)->format('d-m-Y') }}<br><small>Created: {{ $voucher->created_at ? \Carbon\Carbon::parse($voucher->created_at)->format('d-m-Y H:i') : '-' }}</small></td>
            <td><strong>{{ $voucher->organization_name }}</strong><br>{{ $voucher->outlet_name }}<br><small>{{ $voucher->id }}</small></td>
            <td>{{ $voucher->manager_name }}</td>
            <td class="pill {{ $voucher->status === 'variance' ? 'variance' : '' }}">{{ strtoupper($voucher->status) }}@if ($voucher->status === 'open')<br><small>Not submitted</small>@endif</td>
            <td>@if ($submission)<strong>{{ $submission->manager_name }}</strong><br><small>{{ $submission->manager_email }}</small><br><small>Submitted: {{ \Carbon\Carbon::parse($submission->created_at)->format('d-m-Y H:i') }}</small>@else{{ $voucher->creator_name }}<br><small>{{ $voucher->creator_email }}</small><br><small>Draft created</small>@endif</td>
            <td>@if ($approval)<strong>{{ $approval->reviewer_name }}</strong><br><small>{{ $approval->reviewer_email }}</small><br><small>Approved: {{ \Carbon\Carbon::parse($approval->created_at)->format('d-m-Y H:i') }}</small>@elseif ($voucher->status === 'pending_review')<span>Pending review</span><br><small>Not approved yet</small>@else<span>-</span>@endif</td>
            <td>Cash: {{ number_format((int) $voucher->cash_sales_minor) }}<br>Card: {{ number_format((int) $voucher->card_sales_minor) }}<br>Total: {{ number_format($item['displaySalesMinor']) }}</td>
            <td>Expected: {{ number_format($item['displayExpectedCashMinor']) }}<br>Counted: {{ $item['displayCountedCashMinor'] === null ? 'Not counted' : number_format($item['displayCountedCashMinor']) }}<br>Difference: {{ $item['displayCashDifferenceMinor'] === null ? '-' : number_format($item['displayCashDifferenceMinor']) }}<br><strong>{{ $item['displayCashDifferenceMinor'] === null ? 'NOT COUNTED' : ($item['displayCashDifferenceMinor'] === 0 ? 'MATCHED' : 'NOT MATCHED') }}</strong></td>
            <td>Cash: {{ number_format($item['displayCashExpensesMinor']) }}<br>Bank: {{ number_format($item['displayBankExpensesMinor']) }}<br>Total: {{ number_format($item['displayExpensesMinor']) }}<br>Variance: {{ $item['displayVarianceMinor'] === null ? 'Pending' : number_format($item['displayVarianceMinor']) }}</td>
            <td><a class="button" href="{{ route('dashboard.vouchers.show', $voucher->id) }}">View details</a></td>
        </tr>
    @empty
        <tr><td colspan="10">No vouchers match this filter.</td></tr>
    @endforelse
    </tbody></table></div>
</main>
</body>
</html>
