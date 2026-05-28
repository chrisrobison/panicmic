<?php
use function NextUp\Support\e;
use NextUp\Support\Url;
?>
<section class="workspace narrow help-page">
  <header class="admin-page-header">
    <div>
      <h1>Singer help</h1>
      <p class="muted">Everything you need to know to grab the mic at <strong><?= e($tenant['venue_name']) ?></strong>.</p>
    </div>
  </header>

  <article class="panel help-section">
    <h2>The 30-second version</h2>
    <ol>
      <li>Tap <strong>Request</strong> in the top nav (or go to the home page).</li>
      <li>Type the name you want the KJ to call you — a real name or a nickname.</li>
      <li>Search the catalog, tap the song you want, optionally add a note, and hit <strong>Add me to the queue</strong>.</li>
      <li>Watch the screen up front, or check your spot any time on the <a href="<?= e(Url::path('/me')) ?>"><strong>My Spot</strong></a> page.</li>
    </ol>
    <p class="muted">That's it — no account required for most venues.</p>
  </article>

  <article class="panel help-section">
    <h2>Putting in a song</h2>
    <p>Open the <a href="<?= e(Url::path('/')) ?>">Request</a> page. The form is split into three pieces:</p>
    <ul>
      <li><strong>Display name.</strong> What the KJ will say into the mic when it's your turn. Pick something you'll actually recognize from across a noisy room. If you put yourself in twice with the same name, the system will quietly fold those into one entry so you don't get called twice in a row.</li>
      <li><strong>Search the catalog.</strong> Start typing a title or artist. Results blend the venue's own library with the shared catalog. The list updates as you type — tap a song to lock it in. If you don't see a song, ask the KJ; they can add it on the fly.</li>
      <li><strong>Notes (optional).</strong> Use this for things the KJ needs to know — duet partner, key change, "this is my first time," "we're celebrating a birthday." Keep it short.</li>
    </ul>
    <p class="muted">When you submit, the queue page shows you the position you landed in. The KJ may shuffle the order to spread out repeats, balance the night, or honor sign-up bonuses — that's normal.</p>
  </article>

  <article class="panel help-section">
    <h2>Tracking your spot</h2>
    <p>Three ways to know when you're up:</p>
    <ul>
      <li><strong><a href="<?= e(Url::path('/me')) ?>">My Spot</a></strong> — lists every request you've submitted from this device, with its current status (waiting, up next, now singing, done, skipped).</li>
      <li><strong><a href="<?= e(Url::path('/')) ?>">The live queue</a></strong> on the home page shows the upcoming singers in order.</li>
      <li><strong>The big screen</strong> at the venue shows who's singing now and who's up next — that's the source of truth in the room.</li>
    </ul>
    <p class="muted">"My Spot" is tied to your browser session, not an account. If you switch phones or clear your browser data, the link to your requests is lost (but the KJ still has them in the queue).</p>
  </article>

  <article class="panel help-section">
    <h2>Browsing the catalog</h2>
    <p>The full <a href="<?= e(Url::path('/songs')) ?>">Catalog</a> page lets you search and filter without committing to a request. Use it to:</p>
    <ul>
      <li>Look up an artist and see everything available.</li>
      <li>Filter by genre or decade when you can't decide.</li>
      <li>Page through results — the most likely matches are at the top.</li>
    </ul>
    <p class="muted">When you find something, you can either remember the title for the Request page or follow the link to add it directly to the queue.</p>
  </article>

  <article class="panel help-section">
    <h2>Etiquette &amp; ground rules</h2>
    <ul>
      <li><strong>One song at a time.</strong> Most venues only let you have one open request in the queue. Once your song is done you can put another in.</li>
      <li><strong>Be ready when called.</strong> The KJ generally has the next singer queued up while the current one is performing. If you wander off, you may get skipped and moved back.</li>
      <li><strong>Lyrics on the screen are guidance, not gospel.</strong> Different recordings have different arrangements — listen to the playback.</li>
      <li><strong>Songs you don't see in the catalog</strong> may still be doable — ask the KJ. They can usually add a song from YouTube on the spot.</li>
    </ul>
  </article>

  <article class="panel help-section">
    <h2>Troubleshooting</h2>
    <details class="panel inline-form">
      <summary>The Request button doesn't do anything</summary>
      <p>Make sure you've filled in a display name <em>and</em> picked a song from the search results (just typing the title isn't enough — tap the result so it highlights). The button only activates when both are set.</p>
    </details>
    <details class="panel inline-form">
      <summary>My song isn't in the catalog</summary>
      <p>Ask the KJ — they can pull most karaoke tracks from YouTube on demand. If they can't find it, try a different recording or a related song from the same artist.</p>
    </details>
    <details class="panel inline-form">
      <summary>I requested a song but it's not showing on "My Spot"</summary>
      <p>"My Spot" is tied to the browser you submitted from. If you switched phones, opened a private/incognito tab, or cleared site data, the link is lost. Your request is still in the queue — just ask the KJ to confirm it.</p>
    </details>
    <details class="panel inline-form">
      <summary>I got skipped</summary>
      <p>If the KJ called your name and couldn't find you, your request was probably moved to "skipped." Ask them to re-add you — they can put you back in.</p>
    </details>
    <details class="panel inline-form">
      <summary>Can I cancel or change my request?</summary>
      <p>Talk to the KJ. The public site doesn't let singers edit a request once it's submitted — that prevents queue-jumping shenanigans — but the KJ can adjust anything from their console.</p>
    </details>
    <details class="panel inline-form">
      <summary>How does the system know it's me?</summary>
      <p>A token is stored in your browser when you first submit a request. That's what links the requests on "My Spot" to you. It's not an account, there's no password, and the token doesn't follow you to other devices.</p>
    </details>
  </article>

  <article class="panel help-section">
    <h2>Privacy</h2>
    <p>The only thing the venue keeps about you is the display name you typed and a browser-local token tying your requests together for the night. There's no email, no phone number, no tracking pixels. After the session ends, your requests stay in the venue's history but aren't tied to any identity.</p>
  </article>

  <p class="muted help-footer">Still stuck? Find the KJ — they're the person staring at the laptop near the speakers. They can fix anything this page can't.</p>
</section>
