<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signing out: Empower Marketplace</title>
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Inter,system-ui,sans-serif;color:#0a2037;background:#f5f7fa}</style>
</head>
<body>
    <p>Signing you out&hellip;</p>
    {{-- Moodle's OpenID Connect front-channel logout URL ends the LMS session; the iframe is never
    shown. Moodle may refuse to render inside a frame, but the request (and the logout) still runs. --}}
    <iframe src="{{ $logoutUrl }}" title="Empower LMS sign-out" hidden onload="done()"></iframe>
    <script>
        var left = false;
        function done() { if (!left) { left = true; window.location.replace(@js(route('home'))); } }
        setTimeout(done, 3000);
    </script>
</body>
</html>
