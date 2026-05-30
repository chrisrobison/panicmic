<?php
use function PanicMic\Support\e;
use PanicMic\Support\Url;
$current = 'help';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator help-page">
    <header class="admin-page-header">
      <div>
        <h1>KJ help &amp; operator guide</h1>
        <p class="muted">How to run a karaoke night on Panic Mic — the queue, the catalog, the displays, and the things that only matter when something goes sideways.</p>
      </div>
    </header>

    <article class="panel help-section">
      <h2>Before you open the doors</h2>
      <ol>
        <li><strong>Sign in</strong> at <code>/admin/login</code> with the email and password your owner set up. If you're the venue owner and just signed up, check your email for the invite link.</li>
        <li><strong>Confirm branding</strong> on <a href="<?= e(Url::path('/admin/settings')) ?>">Settings</a> — venue name, night name, primary/accent colors, logo. This is what singers and the projection display see all night.</li>
        <li><strong>Pick a song source</strong> on Settings → Request behavior. The shared catalog gives you a massive starting point; flip on local-only if you only want to expose songs you've personally vetted.</li>
        <li><strong>Open the display window</strong> from the dashboard toolbar (or visit <a href="<?= e(Url::path('/display')) ?>">/display</a> on the projector machine). Pop it out and drag it to the second monitor before the first singer signs up.</li>
        <li><strong>Start a session</strong> from the dashboard. The session is what scopes the night — every request, stat, and announcement is attached to it. End the session at close-down so tomorrow starts clean.</li>
      </ol>
    </article>

    <article class="panel help-section">
      <h2>Running the queue</h2>
      <p>The <a href="<?= e(Url::path('/admin/dashboard')) ?>">Dashboard</a> is your home base for the night. It shows the live queue, the four stat tiles (waiting / up next / now singing / completed), the display-mode toggles, and a freeform announcement form.</p>
      <h3>Statuses</h3>
      <ul>
        <li><strong>Pending</strong> — submitted, in line, not called yet. New requests land here.</li>
        <li><strong>Up next</strong> — staged on deck. The projection display highlights up-next entries so singers know to come forward.</li>
        <li><strong>Now singing</strong> — currently at the mic. Only one request can be "now singing" at a time; promoting another auto-completes the previous one.</li>
        <li><strong>Completed</strong> — finished. Stays in the night's history but drops off the public queue.</li>
        <li><strong>Skipped</strong> — singer didn't show up or bailed. Doesn't penalize them; you can flip it back to pending if they reappear.</li>
        <li><strong>Canceled</strong> — used when a request was a mistake or duplicate.</li>
      </ul>
      <h3>Reordering</h3>
      <p>Drag any queue card up or down to move it. The server persists the new order immediately and the public page + display refresh on their own. The standard rule: spread out repeats, alternate between hot singers and warm-up acts, and reward the people who showed up early.</p>
      <h3>Attaching a YouTube video</h3>
      <p>Each queue card has a <em>Find video</em> button. Tap it to auto-search YouTube for a karaoke version of that song. The found URL gets attached to the request and used by the display player when you cue it.</p>
    </article>

    <article class="panel help-section">
      <h2>Display setup</h2>
      <p>Panic Mic supports any number of display screens — one for the lyrics video, one for the up-next list, one for QR codes pointing at the request form, etc.</p>
      <h3>Single screen (most common)</h3>
      <p>On the projector machine, log in and open <code>/display</code>. It auto-fullscreens with the venue branding, the current singer, and the next-up callout. Use the dashboard's display-mode toggles to switch between idle (just QR codes + announcements), queue view, and clean stage (everything off except the current singer's name).</p>
      <h3>Multiple screens</h3>
      <p>On <a href="<?= e(Url::path('/admin/settings')) ?>">Settings → Displays</a>, define a screen for each surface — give each one a slug (<code>main</code>, <code>sidebar</code>, <code>foyer</code>) and pick what it shows. Then from the dashboard, click the screen pill to open a popup tuned for that surface. Chromium browsers will offer to place each popup on a separate monitor if you grant the Window Management permission.</p>
      <p>Use <strong>Cue &amp; Play</strong> on the dashboard toolbar to push the current up-next song onto every screen at once. The displays talk to each other over a browser <code>BroadcastChannel</code>, so they don't have to round-trip through the server for split-second cues.</p>
      <h3>Going fullscreen</h3>
      <p>Open <code>/display/fullscreen</code> instead of <code>/display</code> if you want the display window to enter the browser's fullscreen API on load. Most browsers require a user gesture first — click anywhere on the page if it refuses.</p>
    </article>

    <article class="panel help-section">
      <h2>Catalog management</h2>
      <p>The <a href="<?= e(Url::path('/admin/songs')) ?>">Song catalog</a> page lists songs that belong to <em>this venue</em>. The shared catalog (curated by the platform) is searchable from singer pages automatically — you only need entries here when you want a song that isn't in shared, or you want to override one.</p>
      <h3>Adding a song</h3>
      <p>Click <em>Add song</em> at the top. Fill in title, artist, optional album/genre/decade, and a YouTube link if you have a preferred karaoke version. Save — it appears in the catalog and is immediately searchable from the request form.</p>
      <h3>Importing a YouTube playlist</h3>
      <p>Click <em>Import playlist</em>. Paste a YouTube playlist URL or ID. The system pulls every video's title and artist (best-effort parsing from the video title), and queues them as catalog entries. Big playlists take a few seconds — wait for the confirmation count.</p>
      <h3>Exporting</h3>
      <p>The <em>Export CSV</em> button downloads your local catalog. Useful for backups or for bulk-editing in a spreadsheet and re-importing.</p>
      <h3>Editing or removing</h3>
      <p>Each card has an inline edit button. You can also delete songs — but deleting a song with pending requests in the queue isn't recommended; cancel or complete the request first.</p>
    </article>

    <article class="panel help-section">
      <h2>Content uploads</h2>
      <p>The <a href="<?= e(Url::path('/admin/content')) ?>">Content</a> page stores images, video, audio, and PDFs that belong to your venue. They're served from <code>/files/&lt;your-slug&gt;/</code> and are only accessible on your tenant's domain.</p>
      <ul>
        <li><strong>Logos</strong> and <strong>background images</strong> referenced in Settings → Branding live here.</li>
        <li><strong>Audio bumpers</strong> and <strong>idle videos</strong> for between-singer playback go here too.</li>
        <li>Uploads have file-type and size limits enforced server-side — see the upload form for the current cap.</li>
      </ul>
    </article>

    <article class="panel help-section">
      <h2>Branding &amp; theme</h2>
      <p>On <a href="<?= e(Url::path('/admin/settings')) ?>">Settings → Branding</a>, set the colors that propagate through every page (singer, KJ, and display): primary (the action color), accent (highlights and lower-thirds on the display), background, surface, and text. Pick the logo image and a profile image (shown in the topbar). The background image goes behind everything with a darkening overlay so text stays legible.</p>
      <p class="muted">Tip: pick colors with enough contrast that lyric titles read at 30 feet. The display page uses 8+ vw text by default; if your accent color disappears against the background, the lower-third callouts will too.</p>
    </article>

    <article class="panel help-section">
      <h2>Settings reference</h2>
      <details class="panel inline-form">
        <summary>Signup mode (anonymous / account / both)</summary>
        <p>Controls whether singers can request as a free-text display name only (<code>display_name</code>), must create an account first (<code>account</code>), or are offered both choices. The public form currently uses display-name mode regardless; account mode is reserved for future loyalty features.</p>
      </details>
      <details class="panel inline-form">
        <summary>Allow multiple requests per singer</summary>
        <p>When off, each display-name token can only have one active request at a time — they have to wait until it's called before submitting another. When on, singers can stack the queue. Off is the default and what most venues want.</p>
      </details>
      <details class="panel inline-form">
        <summary>Song source (shared / local / blended)</summary>
        <p>Controls what shows up when singers search. Blended (default) merges your local catalog and the platform shared catalog. Local-only restricts the request form to what you've curated. Shared-only ignores your local edits — rarely useful unless you're auditing.</p>
      </details>
      <details class="panel inline-form">
        <summary>Event retention</summary>
        <p>How long server-sent events and request history stick around. Doesn't affect what singers see — only how far back the dashboard reaches.</p>
      </details>
      <details class="panel inline-form">
        <summary>Login throttling</summary>
        <p>Failed login attempts get rate-limited per email and per IP. If you lock yourself out, wait a few minutes or use a different network — the throttle clears automatically.</p>
      </details>
    </article>

    <article class="panel help-section">
      <h2>Sessions, stats, and the night history</h2>
      <p>A <em>session</em> is one karaoke night. Start it when you open the doors, end it at close-down. Every request and announcement is attached to the session, so:</p>
      <ul>
        <li>The stat tiles show counts for the current session only.</li>
        <li>"Completed" requests stay in the database but drop off the live queue when the session ends.</li>
        <li>Ending a session resets the request counter and the public queue display.</li>
      </ul>
      <p class="muted">If you forget to end a session, requests from yesterday will pollute today's queue. The dashboard warns you if the active session is more than 24 hours old.</p>
    </article>

    <article class="panel help-section">
      <h2>Announcements</h2>
      <p>The announcement form on the dashboard pushes a short message to every connected display for ~10 seconds. Use it for:</p>
      <ul>
        <li>Last-call notices.</li>
        <li>Birthday or special-event shout-outs.</li>
        <li>"Bar is closing in 15 minutes" reminders.</li>
      </ul>
      <p>Announcements don't interrupt a song that's currently playing on the display — they overlay as a banner. Keep them short (one line) so they read across the room.</p>
    </article>

    <article class="panel help-section">
      <h2>Billing &amp; subscription</h2>
      <p>Panic Mic is sold per-tenant. If your venue's subscription lapses, the system enters <strong>read-only</strong> mode automatically:</p>
      <ul>
        <li>Singers can still browse the catalog and the queue.</li>
        <li>No new requests can be submitted, no status changes can be saved.</li>
        <li>You can still log in, change branding, and update billing — those endpoints are explicitly exempt.</li>
      </ul>
      <p>The platform sends reminder emails well before this happens. Talk to your account owner or the platform admins if you're getting paywall errors on actions that used to work.</p>
    </article>

    <article class="panel help-section">
      <h2>Troubleshooting</h2>
      <details class="panel inline-form">
        <summary>The display window is blank or stuck</summary>
        <p>Reload the display page. The dashboard's display-mode toggles also force a re-render — flick one and the screen should snap back. If the player area is empty, the current request probably has no YouTube URL attached; click <em>Find video</em> on the dashboard's queue card.</p>
      </details>
      <details class="panel inline-form">
        <summary>A singer isn't showing on the public queue but is on my dashboard</summary>
        <p>That usually means the request has a status the public queue filters out (completed / skipped / canceled). Flip it back to <em>pending</em> or <em>up next</em> from the dashboard.</p>
      </details>
      <details class="panel inline-form">
        <summary>The Cue &amp; Play button does nothing on remote displays</summary>
        <p><code>BroadcastChannel</code> only works between windows in the same browser profile on the same machine. To control a display running on another computer, set up that computer as a separate display screen and let the server-side state sync push the cue — slower (200–500 ms) but works across machines.</p>
      </details>
      <details class="panel inline-form">
        <summary>I can't find a singer's request</summary>
        <p>The dashboard's queue list includes the current session only. If they submitted before you started today's session (or in a different session) it won't be there — check the previous session's history or have them re-submit.</p>
      </details>
      <details class="panel inline-form">
        <summary>Login throttling locked me out</summary>
        <p>Wait a few minutes. The throttle is per-email and per-IP; switching networks resets the IP side. Don't retry rapidly — that just extends the window.</p>
      </details>
      <details class="panel inline-form">
        <summary>YouTube playlist import skipped songs</summary>
        <p>The importer parses "Artist - Title" from video titles. Videos that don't follow that pattern get a best-guess attribution; some get skipped entirely if they're private, deleted, or geo-blocked. Re-import after the playlist owner fixes their listings, or add the missing entries by hand.</p>
      </details>
      <details class="panel inline-form">
        <summary>The page won't save my changes</summary>
        <p>Open the browser console — most failures show a clear error there (CSRF mismatch, validation error, billing paywall, network drop). If you see a 402 status, your subscription is in read-only mode (see the Billing section).</p>
      </details>
    </article>

    <article class="panel help-section">
      <h2>Keyboard &amp; gesture cheatsheet</h2>
      <ul>
        <li><strong>Esc</strong> closes the help modal, drawer editors, and the impersonation banner's confirmation.</li>
        <li><strong>Alt-click</strong> or <strong>Shift-click</strong> any help link to open it as a full page instead of a modal.</li>
        <li><strong>Long-press</strong> a help link on touch to do the same.</li>
        <li>Display windows respond to standard browser fullscreen shortcuts (F11 on desktop, the icon in browser chrome on tablets).</li>
      </ul>
    </article>

    <article class="panel help-section">
      <h2>Getting more help</h2>
      <p>Platform-level questions (billing, suspended tenants, shared catalog) go through the panicmic.com super-admin team. Anything inside your venue — staffing, scheduling, custom features — is between you and your venue's owner. The repository README has a full architectural overview if you're self-hosting.</p>
    </article>
  </section>
</section>
