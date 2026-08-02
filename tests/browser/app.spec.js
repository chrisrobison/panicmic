import { test, expect } from '@playwright/test';

test('singer can discover and submit a catalog request', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Request a Song' })).toBeVisible();

  await page.getByLabel('Display name').fill('Browser Singer');
  await page.getByLabel('Search catalog').fill('Browser Anthem');
  await expect(page.getByRole('button', { name: /Browser Anthem/ })).toBeVisible();
  await page.getByRole('button', { name: /Browser Anthem/ }).click();
  await page.getByRole('button', { name: 'Submit Request' }).click();

  await expect(page.locator('[data-status]')).toContainText(/submitted|request/i);
  await expect(page.locator('[data-public-queue]')).toContainText('Browser Singer');
});

test('administrator can sign in and manage the team surface', async ({ page }) => {
  await page.goto('/admin/login');
  await page.getByLabel('Email').fill('owner@test.local');
  await page.getByLabel('Password').fill('browser-test-password');
  await page.getByRole('button', { name: 'Sign In' }).click();

  await expect(page).toHaveURL(/\/admin\/dashboard$/);
  await expect(page.getByRole('heading', { name: /Incoming Requests/ })).toBeVisible();
  await page.getByRole('link', { name: 'Team', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Team', exact: true })).toBeVisible();
  await expect(page.locator('[data-team-list]')).toContainText('owner@test.local');
});

test('password reset pages do not disclose whether an account exists', async ({ page }) => {
  await page.goto('/admin/forgot-password');
  await page.getByLabel('Email').fill('not-a-user@test.local');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.locator('[data-status]')).toContainText('If that address belongs');

  await page.goto('/admin/reset-password?token=invalid');
  await expect(page.getByRole('heading', { name: 'Choose a new password' })).toBeVisible();
});

test('marketing host serves indexable product and legal pages', async ({ page }) => {
  await page.goto('http://marketing.test:8787/');
  await expect(page.getByRole('heading', { name: /Your command center/ })).toBeVisible();
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://marketing.test/');

  await page.goto('http://marketing.test:8787/privacy');
  await expect(page.getByRole('heading', { name: 'Privacy Policy' })).toBeVisible();
  await page.goto('http://marketing.test:8787/terms');
  await expect(page.getByRole('heading', { name: 'Terms of Service' })).toBeVisible();
});

test('core public and marketing layouts fit a phone viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const target of ['/', 'http://marketing.test:8787/']) {
    await page.goto(target);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);
  }
});
