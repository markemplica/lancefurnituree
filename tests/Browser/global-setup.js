import { execFileSync, spawn } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

export default async function globalSetup() {
    const databasePath = process.env.FURNICRAFT_BROWSER_DB;
    if (!databasePath) throw new Error('The isolated browser database path is missing.');
    const directory = dirname(databasePath);
    mkdirSync(join(directory, 'private'));
    for (const relative of ['framework/views', 'framework/sessions', 'framework/cache', 'logs', 'app/public']) {
        mkdirSync(join(directory, 'storage', relative), { recursive: true });
    }
    writeFileSync(databasePath, '');
    const environment = {
        ...process.env,
        APP_ENV: 'testing', APP_DEBUG: process.env.FURNICRAFT_BROWSER_DEBUG === '1' ? 'true' : 'false', APP_URL: 'http://127.0.0.1:8001',
        APP_CONFIG_CACHE: join(directory, 'config.php'),
        LARAVEL_STORAGE_PATH: join(directory, 'storage'),
        DB_CONNECTION: 'sqlite', DB_DATABASE: databasePath,
        PRIVATE_STORAGE_ROOT: join(directory, 'private'),
        CACHE_STORE: 'array', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array', GEMINI_API_KEY: '', GEMINI_ENABLED: 'false', LOG_CHANNEL: 'single',
    };
    execFileSync('php', ['artisan', 'migrate', '--force', '--no-ansi'], { env: environment, stdio: 'pipe' });
    execFileSync('php', ['tests/Browser/seed-mobile.php'], { env: environment, stdio: 'pipe' });
    if (!existsSync(join(directory, 'room-upload.png'))) {
        throw new Error('The isolated room-upload fixture was not created.');
    }

    // PHP's built-in server does not consistently expose inherited environment values in $_SERVER.
    // Set the test storage override before Laravel constructs its application paths.
    const publicRoot = resolve('public').replaceAll('\\', '/').replaceAll("'", "\\'");
    const router = join(directory, 'router.php');
    writeFileSync(router, `<?php
$_ENV['LARAVEL_STORAGE_PATH'] = getenv('LARAVEL_STORAGE_PATH');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file('${publicRoot}'.$path)) return false;
require '${publicRoot}/index.php';
`);
    const server = spawn('php', ['-d', `upload_tmp_dir=${directory}`,
        ...(process.env.FURNICRAFT_BROWSER_NO_GD === '1' ? ['-d', 'disable_functions=imagecreatefromstring,imagepng'] : []),
        '-S', '127.0.0.1:8001', '-t', 'public', router], {
        env: environment, stdio: 'ignore', windowsHide: true,
    });
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt++) {
        if (server.exitCode !== null) break;
        try {
            const response = await fetch('http://127.0.0.1:8001/up');
            if (response.ok) { ready = true; break; }
        } catch { /* The server is still starting. */ }
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    if (!ready) {
        server.kill();
        throw new Error('The isolated browser server did not start.');
    }

    return () => {
        server.kill();
        const log = join(directory, 'storage', 'logs', 'laravel.log');
        if (existsSync(log)) copyFileSync(log, join(directory, '..', 'furnicraft-browser-errors.log'));
        rmSync(directory, { recursive: true, force: true });
    };
}
