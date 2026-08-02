import { defineConfig, devices } from '@playwright/test';

const port = 8787;
const tenantHost = `http://test.local:${port}`;

export default defineConfig({
  testDir: './tests/browser',
  fullyParallel: false,
  workers: 1,
  timeout: 30_000,
  expect: { timeout: 7_000 },
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: tenantHost,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ...devices['Desktop Chrome'],
    launchOptions: {
      args: [
        '--host-resolver-rules=MAP test.local 127.0.0.1, MAP marketing.test 127.0.0.1',
      ],
    },
  },
  webServer: {
    command: 'php tests/browser/setup.php && '
      + 'APP_ENV=test '
      + 'SUPER_DB_NAME=panicmic_test_super '
      + 'ALLOWED_HOSTS=test.local,marketing.test,localhost,127.0.0.1 '
      + 'MARKETING_HOST=marketing.test '
      + 'SIGNUP_HOST=disabled.test '
      + 'WEBSOCKET_ENABLED=false '
      + `php -S 127.0.0.1:${port} -t public`,
    url: `http://127.0.0.1:${port}/health`,
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
  },
});
