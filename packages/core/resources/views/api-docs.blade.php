<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API reference</title>
</head>
<body>
    {{-- A standalone page: it uses neither Inertia nor the host app's layout, so it works in any install. --}}
    <script id="api-reference" data-url="{{ $specUrl }}"></script>
    <script
        src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.75.1/dist/browser/standalone.js"
        integrity="sha384-G+2Q2i9l3PPfmYkk0b4BAIUrs7xb/DNaTL1wCYYab4c9dIKlCyiqzC0Qx3eq9Wf6"
        crossorigin="anonymous"
    ></script>
</body>
</html>
