#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, not committed) on a FRESH demo with Polylang Pro
 * and its languages but WITHOUT this plugin (DEMO_SKIP_PLUGIN=1) and with the Supertext stand-in, so the API
 * key check works without a real key. The administrator uploads the plugin zip, activates it, patches
 * Polylang, turns on Polylang's Machine Translation with Supertext and enters the API key. See "Demo movie"
 * in docs/DEVELOPER.md.
 *
 *   SITE_URL=http://polylang-docs.test PLUGIN_ZIP=/path/supertext-polylang.zip DEMO_ADMIN_EMAIL=… \
 *     DEMO_ADMIN_PASSWORD=… npm run movie:install
 *
 * MOVIE_OUT: where the MP4 goes (default tests/e2e/movie/out/Wordpress-Polylang-Supertext-Translation-install.mp4).
 * The key typed in is a made-up placeholder, never a real one. Voice and ffmpeg as in movie.mjs.
 */
import pw from '@playwright/test'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const { chromium } = pw
const B = (process.env.SITE_URL || 'http://polylang-docs.test').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT ?? here('movie/out/Wordpress-Polylang-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const zip = need('PLUGIN_ZIP')

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
// Polylang's "You must translate your static front page" reminder isn't part of the movie.
await page.addInitScript(() => {
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.notice, .error').forEach((n) => {
      if (/static front page/i.test(n.textContent || '')) n.remove()
    })
  })
})

// Sign in as the demo administrator (not recorded): installing plugins is an administrator's task.
await page.goto(`${B}/wp-login.php`)
await page.fill('#user_login', need('DEMO_ADMIN_EMAIL'))
await page.fill('#user_pass', need('DEMO_ADMIN_PASSWORD'))
await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')])
await page.goto(`${B}/wp-admin/plugins.php`)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'polylang-movie-install-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
/** Clicks something that loads a new page, keeping cursor and caption (the loading is cut). */
async function go(locator) {
  await kit.moveTo(locator)
  await ripple()
  await kit.hold(250)
  kit.pause()
  await Promise.all([page.waitForNavigation({ timeout: 120_000 }), locator.click()])
  await page.waitForLoadState('load')
  await kit.sync()
  kit.resume()
}

// 1. Requirements: WordPress with Polylang Pro.
await kit.say('You need WordPress with Polylang Pro, set up with your languages. Download the plugin zip from the latest release on GitHub.', {
  caption: 'WordPress with <b>Polylang Pro</b> · plugin zip from the latest GitHub release',
  during: () => kit.moveTo(page.locator('tr[data-slug="polylang-pro"]').first()),
})

// 2. Upload and activate.
await kit.say('In Plugins, click Add New Plugin, then Upload Plugin.', {
  caption: '<b>Plugins → Add New Plugin → Upload Plugin</b>',
  during: async () => {
    await go(page.locator('.page-title-action').first())
    await kit.click(page.locator('.upload-view-toggle').first())
    await kit.hold(300)
  },
})
await kit.say('Choose the zip file and click Install Now.', {
  caption: 'Choose <b>supertext-polylang.zip</b>, then <b>Install Now</b>',
  during: async () => {
    await kit.moveTo(page.locator('#pluginzip'))
    await page.setInputFiles('#pluginzip', zip)
    await kit.hold(500)
    await go(page.locator('#install-plugin-submit'))
  },
})
await kit.say('Then activate it.', {
  caption: '<b>Activate Plugin</b>',
  during: () => go(page.locator('a.button-primary', { hasText: /Activate/ }).first()),
})

// 3. Patch Polylang (one time).
await kit.say('Open Supertext, Settings. Polylang Pro has no hook for other translation services, so click Patch Polylang once.', {
  caption: '<b>Supertext → Settings</b>: click <b>Patch Polylang</b> (once)',
  during: async () => {
    await go(page.locator('#toplevel_page_supertext-polylang > a').first())
    const patch = page.locator('input[type="submit"][value*="atch"]').first()
    await patch.scrollIntoViewIfNeeded()
    await go(patch)
    await page.evaluate(() => window.scrollTo(0, 0))
    await kit.sync()
  },
})

// 4. Polylang's Machine Translation: Supertext and the API key.
await kit.say("Then open Polylang's Machine Translation settings.", {
  caption: '<b>Languages → Settings → Machine Translation</b>',
  during: async () => {
    await go(page.locator('a.st-cta-wide').first())
    const row = page.locator('#pll-module-machine_translation')
    const activate = row.locator('a', { hasText: /^Activate$/ })
    if (await activate.count()) await go(activate.first())
    await kit.click(page.locator('#pll-module-machine_translation a', { hasText: /^Settings$/ }).first())
    await page.locator('#pll-supertext-api-key').waitFor({ state: 'visible' })
  },
})
await kit.say('In the Supertext section, enter your API key. You generate it on supertext.com, under Integrations, API.', {
  caption: 'Supertext: enter your API key (supertext.com → Integrations → API)',
  during: async () => {
    const key = page.locator('#pll-supertext-api-key')
    await key.scrollIntoViewIfNeeded()
    await kit.type(key, 'st-demo-key-0000')
    await kit.hold(300)
  },
})
await kit.say('Each Polylang language is mapped to a Supertext language. Change it if you need another variant, such as Swiss German.', {
  caption: 'Language mapping: one Supertext language per Polylang language',
  during: async () => {
    const mapping = page.locator('#pll-supertext-lang-de')
    await mapping.scrollIntoViewIfNeeded()
    await kit.moveTo(mapping)
    await kit.hold(600)
  },
})
await kit.say('Save. Supertext checks the key right away.', {
  caption: '<b>Save changes</b>: the key is checked, the connection to Supertext works',
  during: async () => {
    const save = page.locator('#pll-configure-machine_translation button.button-primary, #pll-configure-machine_translation input[type="submit"]').first()
    await save.scrollIntoViewIfNeeded()
    await kit.moveTo(save)
    await ripple()
    kit.pause()
    await save.click()
    // Polylang saves the module by Ajax and closes its settings; the key check shows when they open again.
    await page.locator('#pll-configure-machine_translation').waitFor({ state: 'hidden', timeout: 60_000 }).catch(() => {})
    await page.waitForTimeout(800)
    await page.reload()
    await page.locator('#pll-module-machine_translation a', { hasText: /^Settings$/ }).first().click()
    const check = page.locator('#pll-configure-machine_translation').getByText(/API key checked/).first()
    await check.waitFor({ state: 'visible', timeout: 30_000 })
    await check.scrollIntoViewIfNeeded()
    await page.evaluate(() => window.scrollBy(0, -120))
    await kit.sync()
    kit.resume()
    await kit.moveTo(check)
    await kit.hold(600)
  },
})
await kit.say("That's it. Editors now find Supertext AI Translation in the bulk actions of the posts and pages lists, and Supertext in Polylang's translate buttons.", {
  caption: 'Done: <b>Supertext AI Translation</b> is ready',
  during: () => kit.hold(400),
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Polylang', repo: 'Wordpress-Polylang-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
