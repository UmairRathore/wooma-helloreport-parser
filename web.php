<?php

use Illuminate\Support\Facades\Http;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;


//test automation pdf migration code from helloreport
Route::get('/hr-login-and-fetch', function () {

    $base = 'https://my.helloreport.co.uk';
    $host = 'my.helloreport.co.uk';

    $cookieJar = new CookieJar();

    /**
     * STEP 1: Get CSRF cookie (creates session + XSRF-TOKEN)
     */
    Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false, // local dev only
    ])->get($base . '/api/sanctum/csrf-cookie');

    /**
     * STEP 2: Extract XSRF-TOKEN from cookie jar
     */
    $xsrfToken = null;

    foreach ($cookieJar->toArray() as $cookie) {
        if ($cookie['Name'] === 'XSRF-TOKEN') {
            $xsrfToken = urldecode($cookie['Value']);
            break;
        }
    }

    if (!$xsrfToken) {
        return response()->json([
            'error' => 'XSRF token not found in cookie jar',
        ], 500);
    }

    /**
     * STEP 3: Login WITH X-XSRF-TOKEN header
     */
    $login = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->withHeaders([
        'Accept' => 'application/json',
        'Origin' => $base,
        'Referer' => $base . '/',
        'X-XSRF-TOKEN' => $xsrfToken,
    ])->post($base . '/api/login', [
        'email' => '',
//      'password' => ,
        'recaptchaToken' => null,
    ]);

    if (!$login->successful()) {
        return response()->json([
            'step' => 'login',
            'status' => $login->status(),
            'body' => $login->json(),
        ]);
    }

    /**
     * STEP 4: Authenticated request (cookies already valid)
     */
    $user = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->post($base . '/api/get-user-by-email', [
        'email' => '',
//      'password' => ,
    ]);

    return response()->json([
        'step' => 'get-user-by-email',
        'status' => $user->status(),
        'body' => $user->json(),
    ]);
});


Route::get('/hr-fetch-and-download', function () {

    $base = 'https://my.helloreport.co.uk';
    $host = 'my.helloreport.co.uk';

    // 1️⃣ Cookie jar (session persistence)
    $cookieJar = new CookieJar();

    // 2️⃣ Get CSRF cookie
    Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false, // local dev only
    ])->get($base . '/api/sanctum/csrf-cookie');

    // 3️⃣ Extract XSRF token
    $xsrfToken = null;
    foreach ($cookieJar->toArray() as $cookie) {
        if ($cookie['Name'] === 'XSRF-TOKEN') {
            $xsrfToken = urldecode($cookie['Value']);
            break;
        }
    }

    if (!$xsrfToken) {
        return response()->json(['error' => 'XSRF token missing'], 500);
    }

    // 4️⃣ Login
    $login = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->withHeaders([
        'Accept' => 'application/json',
        'Origin' => $base,
        'Referer' => $base . '/',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-XSRF-TOKEN' => $xsrfToken,
    ])->post($base . '/api/login', [
        'email' => '',
//      'password' => ,
        'recaptchaToken' => null,
    ]);

    $cookiesAfterLogin = $cookieJar->toArray();

//dump($cookiesAfterLogin);
    if (!$login->successful()) {
        return response()->json([
            'step' => 'login',
            'status' => $login->status(),
            'body' => $login->json(),
        ], 500);
    }

    // 5️⃣ Fetch reports list (page 1, completed)
    $reports = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->withHeaders([
        'Accept' => 'application/json',
        'Origin' => $base,
        'Referer' => $base . '/',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-XSRF-TOKEN' => $xsrfToken,
    ])->get($base . '/api/reports', [
        'filter' => [
            'by_status' => 4,
        ],
        'sort' => '-report_date',
        'page' => 1,
        'limit' => 2,
    ]);


    if (!$reports->successful()) {
        return response()->json([
            'step' => 'fetch_reports',
            'status' => $reports->status(),
            'body' => $reports->json(),
        ], 500);
    }

    $downloaded = [];

    // 6️⃣ Download PDFs
    foreach ($reports->json('data') as $report) {

        if (empty($report['pdf']['full_path'])) {
            continue;
        }

        $filename = Str::slug($report['property']['address'] ?? 'report')
            . '-' . $report['id'] . '.pdf';

        $path = public_path('helloreportPDFs/' . $filename);

        $pdfResponse = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
        ])->get($report['pdf']['full_path']);

        if ($pdfResponse->successful()) {
            file_put_contents($path, $pdfResponse->body());
        }


        $downloaded[] = [
            'report_id' => $report['id'],
            'file' => 'public/helloreportPDFs/' . $filename,
        ];
    }

    return response()->json([
        'status' => 'success',
        'downloaded_files' => $downloaded,
    ]);
});

Route::get('/hr-fetch-first-and-last', function () {

    $base = 'https://my.helloreport.co.uk';
    $cookieJar = new CookieJar();

    /* -------------------------------------------------
     | STEP 1: CSRF cookie
     ------------------------------------------------- */
    Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->get($base . '/api/sanctum/csrf-cookie');

    $xsrfToken = null;
    foreach ($cookieJar->toArray() as $cookie) {
        if ($cookie['Name'] === 'XSRF-TOKEN') {
            $xsrfToken = urldecode($cookie['Value']);
            break;
        }
    }

    if (!$xsrfToken) {
        return response()->json(['error' => 'XSRF token missing'], 500);
    }

    /* -------------------------------------------------
     | STEP 2: Login
     ------------------------------------------------- */
    $login = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->withHeaders([
        'Accept' => 'application/json',
        'Origin' => $base,
        'Referer' => $base . '/',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-XSRF-TOKEN' => $xsrfToken,
    ])->post($base . '/api/login', [

        'email' => '',
//      'password' => ,
        'recaptchaToken' => null,
    ]);

    if (!$login->successful()) {
        return response()->json([
            'step' => 'login',
            'status' => $login->status(),
            'body' => $login->json(),
        ], 500);
    }


    /* -------------------------------------------------
  | STEP 3: Fetch FIRST page (WITH CSRF CONTEXT)
  ------------------------------------------------- */
    $firstPage = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->withHeaders([
        'Accept' => 'application/json',
        'Origin' => $base,
        'Referer' => $base . '/',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-XSRF-TOKEN' => $xsrfToken,
    ])->get($base . '/api/reports', [
        'filter' => ['by_status' => 4],
        'sort' => '-report_date',
        'page' => 1,
        'limit' => 1,
    ]);

    if (!$firstPage->successful()) {
        return response()->json([
            'step' => 'first_page',
            'status' => $firstPage->status(),
            'body' => $firstPage->json(),
        ], 500);
    }

    $firstReport = $firstPage->json('data.0');

    /* -------------------------------------------------
     | STEP 4: Fetch LAST page (same headers)
     ------------------------------------------------- */
    $meta = $firstPage->json('meta');
    $lastPageNumber = $meta['last_page'] ?? null;

    if (!$lastPageNumber) {
        return response()->json(['error' => 'Pagination meta missing'], 500);
    }

    $lastPage = Http::withOptions([
        'cookies' => $cookieJar,
        'verify' => false,
    ])->withHeaders([
        'Accept' => 'application/json',
        'Origin' => $base,
        'Referer' => $base . '/',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-XSRF-TOKEN' => $xsrfToken,
    ])->get($base . '/api/reports', [
        'filter' => ['by_status' => 4],
        'sort' => '-report_date',
        'page' => $lastPageNumber,
        'limit' => 1,
    ]);

    if (!$lastPage->successful()) {
        return response()->json([
            'step' => 'last_page',
            'status' => $lastPage->status(),
            'body' => $lastPage->json(),
        ], 500);
    }

    $lastReport = $lastPage->json('data.0');

    /* -------------------------------------------------
     | STEP 5: Download PDFs
     ------------------------------------------------- */
    $downloaded = [];

    foreach ([
                 'first' => $firstReport,
                 'last' => $lastReport,
             ] as $label => $report) {

        if (empty($report['pdf']['full_path'])) {
            continue;
        }

        $filename = $label . '-' . $report['id'] . '.pdf';
        $path = public_path('helloreportPDFs/' . $filename);

        $pdfResponse = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
        ])->get($report['pdf']['full_path']);

        if ($pdfResponse->successful()) {
            file_put_contents($path, $pdfResponse->body());

            $downloaded[] = [
                'type' => $label,
                'report_id' => $report['id'],
                'file' => 'public/helloreportPDFs/' . $filename,
            ];
        }
    }

    return response()->json([
        'status' => 'success',
        'files' => $downloaded,
    ]);
});