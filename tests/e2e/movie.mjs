#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) on a FRESH demo with Polylang Pro, the
 * Supertext stand-in (tests/e2e/stand-in/) and the sample content: the sample page has no translations
 * yet. The demo editor ticks "Welcome to the Supertext demo" in the pages list, translates it into German
 * with the Supertext AI Translation bulk action and opens the German draft. See "Demo movie" in
 * docs/DEVELOPER.md.
 *
 *   SITE_URL=http://polylang-docs.test DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/ (git-ignored);
 * MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/e2e/movie/out/Wordpress-Polylang-Supertext-Translation.mp4, git-ignored, never committed). Needs ffmpeg.
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
const out = process.env.MOVIE_OUT ?? here('movie/out/Wordpress-Polylang-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const PAGE = 'Welcome to the Supertext demo'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
// Polylang's "You must translate your static front page" reminder and the block editor's notices
// aren't part of the movie.
await page.addInitScript(() => {
  const tidy = () => {
    if (!document.head) return
    if (!document.getElementById('stk-tidy')) {
      const s = document.createElement('style')
      s.id = 'stk-tidy'
      s.textContent = '.components-notice-list{display:none!important}'
      document.head.appendChild(s)
    }
    document.querySelectorAll('.notice, .error').forEach((n) => {
      if (/static front page/i.test(n.textContent || '')) n.remove()
    })
  }
  document.addEventListener('DOMContentLoaded', tidy)
})

// Sign in as the demo editor (WordPress's Editor role) — not recorded.
await page.goto(`${B}/wp-login.php`)
await page.fill('#user_login', need('DEMO_EDITOR_EMAIL'))
await page.fill('#user_pass', need('DEMO_EDITOR_PASSWORD'))
await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')])
await page.goto(`${B}/wp-admin/edit.php?post_type=page&lang=en`)

async function openEditor(url) {
  await page.goto(url)
  const guide = page.locator('.components-modal__frame')
  if (await guide.first().waitFor({ state: 'visible', timeout: 6000 }).then(() => true).catch(() => false)) {
    await page.keyboard.press('Escape')
    await guide.first().waitFor({ state: 'hidden' }).catch(() => {})
  }
  await page.locator('.editor-styles-wrapper, iframe[name="editor-canvas"]').first().waitFor()
  await page.waitForTimeout(1200)
}

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'polylang-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
const row = page.locator('#the-list tr', { hasText: PAGE }).first()

// 1. The English pages in WordPress with Polylang.
await kit.say("Here are your pages in English, in WordPress with Polylang.", { caption: 'Your pages in English, in WordPress with Polylang' })

// 2. Tick the page, choose the bulk action and German.
await kit.say('Tick the page you want to translate.', {
  caption: 'Tick the page',
  during: async () => {
    const box = row.locator('input[name="post[]"]')
    await kit.moveTo(box)
    await ripple()
    await box.check()
  },
})
await kit.say('Under Bulk actions, choose Supertext AI Translation, then German as the target language.', {
  caption: '<b>Bulk actions</b> → <b>Supertext AI Translation</b>, target language <b>Deutsch</b>',
  during: async () => {
    const action = page.locator('#bulk-action-selector-top')
    await kit.moveTo(action)
    await ripple()
    await action.selectOption('supertext_ai_translation')
    await kit.hold(500)
    const target = page.locator('select[name="supertext_target_lang"]').first()
    await target.waitFor()
    await kit.moveTo(target)
    await ripple()
    await target.selectOption({ label: 'Deutsch' })
    await kit.hold(400)
    await kit.moveTo(page.locator('#doaction'))
  },
  after: 0,
})
await kit.say('Click Apply. Supertext translates the page.', {
  caption: 'Click <b>Apply</b>: Supertext translates the page…',
  during: async () => {
    await ripple()
    await kit.hold(250)
    kit.pause() // the translation and the reload are cut
    await Promise.all([page.waitForNavigation({ timeout: 300_000 }), page.click('#doaction')])
    await page.locator('#the-list').waitFor()
    await page.waitForTimeout(1200)
    await kit.sync()
    kit.resume()
  },
})

// 3. The result: a German draft, linked in Polylang.
const done = page.locator('.notice-success').first()
await kit.say('A few seconds later, the German translation is there, as a draft linked to the English page in Polylang.', {
  caption: 'Done: a German draft, linked to the original in Polylang',
  during: async () => {
    await kit.moveTo(done)
    await kit.hold(600)
    await kit.moveTo(row.locator('td.column-language_de a').first())
  },
})
await kit.say("Let's open it.", {
  caption: 'Click the pencil under the German flag to open the translation',
  during: async () => {
    const open = row.locator('td.column-language_de a').first()
    await kit.moveTo(open)
    await ripple()
    await kit.hold(250)
    kit.pause() // loading the editor is cut
    const href = await open.getAttribute('href')
    await openEditor(new URL(href, B).href)
    await kit.sync()
    kit.resume()
  },
})
await kit.say('The German page is ready. Review it, and publish it as usual.', {
  caption: 'The German page is ready – review it and publish',
  during: async () => { await kit.hold(400); await kit.moveTo(page.locator('.editor-post-publish-button, .editor-post-publish-panel__toggle').first()) },
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Polylang', repo: 'Wordpress-Polylang-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
