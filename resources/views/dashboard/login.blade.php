<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dashboard login</title><style>body{font-family:system-ui,sans-serif;background:#102a2e;color:#f5f0e8;display:grid;place-items:center;min-height:100vh;margin:0}.panel{background:#183e42;padding:2rem;max-width:380px;width:calc(100% - 3rem);border:1px solid #3d7471}h1{margin-top:0}label{display:block;margin:1rem 0 .35rem}input{box-sizing:border-box;width:100%;padding:.7rem;background:#0f292d;color:#fff;border:1px solid #5d9490}button{margin-top:1.25rem;width:100%;padding:.75rem;background:#e5b567;border:0;color:#152b2c;font-weight:700;cursor:pointer}.error{color:#ffb4a8;font-size:.9rem}</style></head>
<body><main class="panel"><h1>Operations dashboard</h1><p>Administrator access only.</p>
@if ($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
<form method="post" action="{{ route('dashboard.login.store') }}">@csrf
<label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
<label for="password">Password</label><input id="password" name="password" type="password" required>
<button type="submit">Sign in</button></form></main></body></html>