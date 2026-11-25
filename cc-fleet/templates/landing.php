<?php
/**
 * CC Landing: the home page. Built from cc-site/template.html by build.py.
 * Full-page template: the theme's header and styles are left out so the page looks exactly like the design.
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="description" content="Borrow a premium gravel or road bike from the Community Connect × Standert fleet in Cape Town. Free for anyone. Ride it, look after it, pass it on.">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'cc-landing' ); ?>>
<?php wp_body_open(); ?>
<p class="notice mono" role="note">We’re building our new website · until then, borrow bikes right here</p>
<header class="top">
  <div class="max">
    <button class="ibtn menu-btn" id="menuOpen" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
    <a class="brand" href="#top" aria-label="Community Connect, back to top"><i><svg viewBox="0 0 400 400" fill="currentColor" aria-hidden="true"><?php echo CC_Fleet_Landing::svg( 'icon' ); // phpcs:ignore ?></svg></i>Community Connect</a>
    <nav class="links" aria-label="Sections"><a href="#fleet">Fleet</a><a href="#how">How it works</a><a href="#events">Events</a><a href="#deal">The deal</a><a href="#agreement">Agreement</a></nav>
    <a class="ibtn" href="https://www.instagram.com/cc_x_standert/" aria-label="Community Connect on Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1" fill="currentColor" stroke="none"/></svg></a>
    <a class="pillbtn pink sm" href="#fleet">Borrow a bike →</a>
  </div>
</header>

<div class="menu" id="menu" hidden role="dialog" aria-modal="true" aria-label="Menu">
  <button class="close" id="menuClose" aria-label="Close menu">✕</button>
  <nav aria-label="Menu"><a href="#fleet">Fleet</a><a href="#how">How it works</a><a href="#events">Events</a><a href="#deal">The deal</a><a href="#ask">Ask for any bike</a><a href="#agreement">Agreement</a></nav>
  <div class="social"><a class="ibtn" href="https://www.instagram.com/cc_x_standert/" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/></svg></a><a class="ibtn" href="mailto:contact@community-connect.cc" aria-label="Email us"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6 8.5 7 8.5-7"/></svg></a></div>
  <a class="card" href="#fleet"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/cc-12.webp" alt="" loading="lazy"><b>Borrow a bike →</b></a>
</div>

<main id="top">
<section aria-label="Highlights">
  <div class="slides" id="slides">
    <a class="slide" href="#how" aria-label="We share bikes. Here is how you ride one."><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/hero-ghc.webp" alt="A pink Standert gravel bike hanging in a sunlit workshop"><span class="ov"><span class="tag">Free to borrow</span><b>We share bikes.</b><span class="l2">Here is how you ride one.</span><span class="hand">10 standerts, 0 fees x</span></span></a>
    <a class="slide" href="#fleet" aria-label="Gravel Hearts Club: two bikes repainted"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/repaint.webp" alt="A frame being repainted pink by hand"><span class="ov"><span class="tag">New project</span><b>Gravel Hearts Club</b><span class="l2">Cute and Dirty</span><span class="hand">2 bikes, repainted x</span></span></a>
    <a class="slide" href="#events" aria-label="Events 2027: ride it on a fleet bike"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/desolation.webp" alt="A rider carrying a frame, motion blurred"><span class="ov"><span class="tag">Events 2027</span><b>Ride it on a fleet bike</b><span class="l2">Races, social rides, overnights.</span><span class="hand">racing terms for races x</span></span></a>
  </div>
  <div class="dots" id="dots" role="group" aria-label="Choose a highlight"></div>
</section>

<section class="sect" id="fleet" aria-labelledby="fleetT">
  <div class="in max">
    <h2 class="h" id="fleetT">Explore the fleet</h2>
    <p class="sub"><span id="nBikes">10</span> Standerts · free to borrow · usually up to 7 days · book up to <span id="horizon">31 Dec 2027</span></p>
  </div>
<!--cc:fleet-->
  <div class="in max">
    <div class="finder">
      <div class="frow"><label class="mono" for="h">What’s my size?</label><output for="h" id="hv">176 cm</output></div>
      <input id="h" type="range" min="150" max="200" value="176" step="1" aria-describedby="fitMsg">
      <p id="fitMsg">Probably size 54 or 56.</p>
    </div>
    <div class="chips" role="group" aria-label="Filter bikes" id="chips"></div>
    <div class="avail" id="avail">
      <div class="avail__box">
        <button type="button" class="avail__f" id="availA" data-dp><span class="mono">Pick-up</span><b>Add date</b></button><button type="button" class="avail__f" id="availB" data-dp><span class="mono">Return</span><b>Add date</b></button>
      </div>
      <button type="button" class="pillbtn ink avail__go" id="availGo" data-dp>Check availability</button>
      <button type="button" class="avail__clear" id="availClear" hidden>Clear dates</button>
    </div>
    <p class="count" id="msg" aria-live="polite"></p>
  </div>
  <ul class="grid max" id="list"></ul>
<!--/cc:fleet-->
</section>

<aside class="banner" aria-label="Fleet news">
  <div class="max"><p class="mono">Fleet 2027</p><p class="h">New Kieswerks in 52 and 56 are on the way.</p><span class="hand">bye bye Erdgeschoss 54 + 56 x</span></div>
</aside>

<section id="how" aria-labelledby="howT">
  <a class="photo" href="#how-steps"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/getaways.webp" alt="A fleet bike leaning in a bright room" loading="lazy"><span class="ov"><b id="howT">How it works</b><span class="l2">Six steps, mostly on your phone.</span><span class="hand">one free day between loans x</span></span></a>
  <div class="in max">
<!--cc:loop-->
    <figure class="loop" id="route" aria-label="Animation: the Community Connect icon travels a loop past the six steps of a loan" style="margin-bottom:0">
      <svg id="track" aria-hidden="true"></svg>
      <div class="say" id="say" aria-live="polite"><i id="sayN">Step 1 of 6</i><b id="sayB">Pick a bike</b><span id="sayS">Find one in your size that’s free on your dates.</span></div>
      <div class="ctrl"><button id="prev" aria-label="Previous step">‹</button><button id="play" aria-label="Pause animation">Pause</button><button id="next" aria-label="Next step">›</button></div>
    </figure>
<!--/cc:loop-->
  </div>
  <div class="row max" id="how-steps" role="list"></div>
</section>

<section class="sect" id="events" aria-labelledby="evT">
  <div class="in max"><h2 class="h" id="evT">Events</h2><p class="sub">Gravel races with dates out. Pick one on the request form and we plan the bike around it.</p></div>
<!--cc:events--><ul class="evs max" id="evList"></ul><!--/cc:events-->
  <div class="terms max">
    <div class="cream"><span class="st" style="background:var(--ochre);border-color:var(--ochre);color:#fff">Racing terms</span><p><b>Racing is harder on a bike than riding.</b> For an event you tick one extra line: damage while racing, crashes included, is yours to cover. Repairs at Woodstock Cycleworks, invoice shared, no markup.</p></div>
    <div class="pinkb"><span class="st reserved">Raffle bikes</span><p><b>Win a bike, an entry, sometimes kit and nutrition.</b> Some bikes stay held for organisers’ raffles until the winner is drawn. They show as raffle bikes and go back into the pool if nobody claims them.</p></div>
  </div>
  <p class="src max">Dates as published by the organisers. Always confirm on the event’s own page before you enter.</p>
</section>

<!--cc:stats--><section class="stats max" aria-label="The last 12 months in numbers">
  <div class="stat stat--loans"><div class="stat__k">01 · Loans</div><div class="stat__n"><svg class="stat__icon" viewBox="0 72 400 256" aria-hidden="true" focusable="false"><path d="M144.93,187.93l-29.08,29.07.97,1.4c16.39,23.62,4.98,58.26-27.24,65.08-9.59,2.03-19.68.21-27.96-5.05-27.8-17.66-26.38-54.11-2.76-70.5l2.83-1.96c.69-.48.91-1.39.52-2.13l-17.71-33.24c-.4-.75-1.35-1.04-2.09-.63-2.35,1.31-4.65,2.74-6.91,4.3-45.28,31.42-49.42,100,.13,136.2,19.27,14.07,44.13,18.57,67.1,12.12,59.07-16.56,79.16-82.26,47.74-127.54-1.74-2.5-3.59-4.87-5.55-7.12Z"/><path d="M87.56,97.26c0-12.57-10.19-22.73-22.74-22.73s-22.7,10.16-22.7,22.73,10.14,22.72,22.7,22.72,22.74-10.17,22.74-22.72Z"/><path d="M219.92,299.69h.2c6.79,0,12.33-5.54,12.33-12.37V100.47c-24.88,24.88-49.74,49.79-74.63,74.68-.68.68-1.4,1.38-2.09,2.06-5.16,5.21-5.16,13.59,0,18.8,3.53,3.48,7.04,6.99,10.5,10.48,9.94,9.94,19.93,19.93,29.94,29.87,3.79,3.82,7.57,7.64,11.41,11.44v39.53c0,6.82,5.54,12.37,12.35,12.37ZM186.13,186.6l21.44-21.46v42.92l-21.44-21.46Z"/><path d="M332.63,281.94c-.28-.79-1.11-1.24-1.93-1.05l-3.35.77c-28.02,6.43-55.93-17.05-50.28-49.5,1.68-9.66,7.13-18.35,15.09-24.08,26.73-19.23,60.04-4.36,66.47,23.66l.77,3.35c.19.82.96,1.36,1.79,1.27l37.44-4.07c.85-.09,1.47-.86,1.36-1.71-.34-2.67-.81-5.34-1.43-8.01-12.33-53.72-74.45-83.08-126.47-50.55-20.23,12.65-33.65,34.06-36.21,57.79-6.59,61,46.92,104.1,100.64,91.77,2.67-.61,5.29-1.35,7.83-2.21.81-.27,1.23-1.16.94-1.97l-12.67-35.47Z"/><path d="M137.91,177.81l2.78-2.77,1.69-1.68c2.07-2.26,4.14-4.52,6.18-6.79,4.65-5.13,4.6-12.97-.11-17.99-.36-.4-.72-.76-1.11-1.16-4.18-4.33-8.36-8.65-12.57-12.96,12.57-.4,25.12-.77,37.69-1.16,14.41-14.41,28.82-28.84,43.28-43.29h-105.38c-20.49,0-30.87,24.72-16.52,39.36.61.61,1.2,1.24,1.75,1.87,3.05,3.5,6,7.02,9.06,10.51.27.28.63.57.89.86,2.28,2.49,4.57,4.97,6.86,7.45h0s8.6,9.35,8.6,9.35l-25.52,25.41c-5.64,5.62-6.31,14.11-1.51,18.93l.14.14c4.79,4.8,13.29,4.15,18.93-1.47"/><polygon points="297.63 133.56 297.63 106.35 307.14 106.35 307.14 100.45 279.88 100.45 279.88 106.35 289.37 106.35 289.37 133.56 297.63 133.56"/><polygon points="311.46 100.45 311.46 133.56 319.23 133.56 319.23 109.59 319.39 109.59 328.11 133.56 333.46 133.56 342.15 109.59 342.33 109.59 342.33 133.56 350.1 133.56 350.1 100.45 338.95 100.45 330.81 121.83 322.69 100.45 311.46 100.45"/></svg><b id="sLoans" data-count>115</b></div><div class="stat__l">loans in the last 12 months</div></div>
  <div class="stat"><div class="stat__k">02 · Riders</div><div class="stat__n"><b id="sRiders" data-count>102</b></div><div class="stat__l">riders on our bikes</div><div class="stat__hand">and counting x</div></div>
  <div class="stat"><div class="stat__k">03 · Calendar</div><div class="stat__n"><svg class="stat__icon stat__icon--flag" viewBox="0 0 193.7 100.8" aria-hidden="true" focusable="false"><polygon points="37.9 43.7 37.8 43.7 37.5 44.8 38.3 44.8 37.9 43.7" fill="#141217"/><path d="M12.9,38.5c1.4-.6,2.9-.9,4.6-.9s2.4.2,3.5.5c1.1.4,2.1.9,3,1.6.9.7,1.6,1.5,2.2,2.5.4.8.7,1.6.9,2.6h5.7l2.5-6.6h5.1l2.5,6.6h5.6v-22.4H.1v22.4h7.2c.5-1.4,1.2-2.7,2.2-3.7.9-1.1,2.1-1.9,3.5-2.5L12.9,38.5Z" fill="#141217"/><path d="M21.9,44.1c-.3-.5-.7-.9-1.1-1.2-.4-.4-.9-.6-1.5-.8s-1.1-.3-1.7-.3c-1.1,0-2.1.2-2.8.6-.8.4-1.4,1-1.9,1.7l-.3.6h9.7c0-.2-.2-.4-.3-.6H21.9Z" fill="#141217"/><rect x="48.5" width="48.4" height="22.4" fill="#141217"/><path d="M127.1,44.1c-.5-.7-1.1-1.3-1.9-1.7-.8-.4-1.7-.6-2.8-.6s-2.1.2-2.8.6c-.8.4-1.4,1-1.9,1.7q-.15.3-.3.6h10.1q-.15-.3-.3-.6H127.1Z" fill="#141217"/><path d="M96.9,22.4v15.7h14.4v4.2h-6.7v2.5h7.5c.5-1.4,1.2-2.7,2.2-3.7.9-1.1,2.1-1.9,3.5-2.5s2.9-.9,4.6-.9,3.3.3,4.7.9c1.4.6,2.5,1.4,3.5,2.5.9,1.1,1.7,2.3,2.2,3.7h2.2l-1.8-6.6h4.9l1.5,6.6h4.3l1.4-5.5v-16.9h-48.4H96.9Z" fill="#141217"/><rect x="96.9" y="42.3" width="2.7" height="2.5" fill="#141217"/><rect x="145.3" width="48.4" height="22.4" fill="#141217"/><polygon points="48.5 56 47.1 56 48.5 59.6 48.5 56" fill="#141217"/><path d="M14.8,56.3c.8.4,1.7.6,2.8.6s2.3-.3,3.1-1h-6.3c.2.1.3.2.4.3V56.3Z" fill="#141217"/><path d="M43.7,60.6l-1.6-4.6h-8.7l-1.6,4.6h-5l1.7-4.6h-2.3c-.5,1-1.1,1.9-1.9,2.6q-1.35,1.2-3,1.8c-1.2.4-2.4.6-3.8.6s-3.3-.3-4.6-.9c-1.4-.6-2.5-1.4-3.5-2.5-.5-.5-.9-1.1-1.3-1.7H0v22.4h48.4v-17.8h-4.8L43.7,60.6Z" fill="#141217"/><path d="M54.2,48.7h3.8c.6,0,1.1,0,1.6-.1.5,0,1-.2,1.4-.5.4-.2.7-.6,1-1,.2-.4.4-1,.4-1.7s0-.4,0-.6h-8.1v3.9h-.1Z" fill="#141217"/><polygon points="73.6 47.1 84.5 47.1 84.5 50.9 73.6 50.9 73.6 55.9 96.9 55.9 96.9 44.8 73.6 44.8 73.6 47.1" fill="#141217"/><path d="M67.1,44.8v.6c0,.9-.1,1.8-.4,2.7-.3.9-.7,1.6-1.4,2.3-.6.7-1.4,1.2-2.4,1.6q-1.5.6-3.6.6h-5.2v3.4h14.5v-11.2h-1.6.1Z" fill="#141217"/><rect x="48.5" y="44.8" width=".7" height="11.2" fill="#141217"/><path d="M122.4,57c1.1,0,2.1-.2,2.8-.6.2,0,.3-.2.4-.3h-6.6c.2.1.3.2.4.3.8.4,1.7.6,2.8.6H122.4Z" fill="#141217"/><path d="M139.1,60.6l-1.2-4.6h-6.1c-.4.6-.8,1.2-1.3,1.7-.9,1-2.1,1.9-3.5,2.5s-2.9.9-4.7.9-3.3-.3-4.6-.9c-1.4-.6-2.5-1.4-3.5-2.5-.5-.5-.9-1.1-1.3-1.7h-8.4v4.6h-4.9v-4.6h-2.7v22.4h48.4v-22.4h0l-1.1,4.6H139.1Z" fill="#141217"/><polygon points="177.7 53.2 177.7 44.8 172.4 44.8 177.6 53.2 177.7 53.2" fill="#141217"/><rect x="182.3" y="44.8" width="11.4" height="11.2" fill="#141217"/><polygon points="167.9 45.5 167.9 55.9 174.5 55.9 168 45.5 167.9 45.5" fill="#141217"/><polygon points="154.1 53.6 156.2 44.8 151.9 44.8 154 53.6 154.1 53.6" fill="#141217"/><polygon points="147.8 45.3 145.3 55.6 145.3 55.9 150.5 55.9 147.9 45.3 147.8 45.3" fill="#141217"/><polygon points="163.3 55.9 163.3 44.8 160.8 44.8 157.8 55.9 163.3 55.9" fill="#141217"/><rect x="48.5" y="78.4" width="48.4" height="22.4" fill="#141217"/><rect x="145.3" y="78.4" width="48.4" height="22.4" fill="#141217"/><path d="M27.5,51.9h-4.8c-.2,1.5-.7,2.7-1.6,3.6-.1.2-.3.3-.5.4h5.7s0-.2.1-.3c.5-1.1.9-2.4,1-3.8L27.5,51.9Z"/><path d="M12.9,54.6c-.5-.7-.8-1.5-1.1-2.4-.2-.9-.3-1.8-.3-2.8s.1-1.9.3-2.9c.2-.7.4-1.3.7-1.8h-5.2c-.5,1.4-.8,3-.8,4.7s.3,3.2.8,4.6c.3.7.6,1.4.9,2h6.1c-.6-.4-1.1-.8-1.4-1.4h0Z"/><path d="M27.3,45.7c0-.3,0-.6-.1-.9h-4.9q.15.45.3.9h4.8H27.3Z"/><path d="M12.9,44.1c.5-.7,1.1-1.3,1.9-1.7s1.7-.6,2.8-.6,1.2.1,1.7.3c.6.2,1.1.5,1.5.8.4.4.8.8,1.1,1.2l.3.6h4.9c-.2-.9-.5-1.8-.9-2.6-.6-1-1.3-1.9-2.2-2.5-.9-.7-1.9-1.2-3-1.6-1.1-.4-2.3-.5-3.5-.5s-3.3.3-4.6.9c-1.4.6-2.5,1.4-3.5,2.5-.9,1.1-1.7,2.3-2.2,3.7h5.2l.3-.6L12.9,44.1Z" fill="#F4EEE4"/><path d="M12.9,60.2c1.4.6,2.9.9,4.6.9s2.7-.2,3.8-.6q1.8-.6,3-1.8c.8-.7,1.4-1.6,1.9-2.6h-5.7c-.8.6-1.8,1-3.1,1s-2.1-.2-2.8-.6c-.2,0-.3-.2-.4-.3h-6.1c.4.6.8,1.2,1.3,1.7.9,1,2.1,1.9,3.5,2.5v-.2Z" fill="#F4EEE4"/><polygon points="40.7 51.9 34.9 51.9 37.5 44.8 32.9 44.8 28.6 56 33.5 56 33.6 55.6 42 55.6 42.2 56 47.1 56 42.9 44.8 38.3 44.8 40.7 51.9"/><polygon points="48.9 60.6 48.5 59.6 48.5 60.6 48.9 60.6"/><polygon points="35.4 38.1 32.9 44.8 37.5 44.8 37.8 43.7 37.9 43.7 38.3 44.8 42.9 44.8 40.5 38.1 35.4 38.1" fill="#F4EEE4"/><polygon points="26.9 60.6 31.9 60.6 33.5 56 28.6 56 26.9 60.6" fill="#F4EEE4"/><polygon points="43.7 60.6 48.5 60.6 48.5 59.6 47.1 56 42.2 56 43.7 60.6" fill="#F4EEE4"/><path d="M54.2,42h3.8c.6,0,1.1,0,1.6.1.5,0,1,.2,1.4.5.4.2.7.6,1,1q.3.45.3,1.2h4.8c0-.7-.2-1.4-.4-2.1-.3-.9-.7-1.6-1.4-2.3-.6-.7-1.4-1.2-2.4-1.6q-1.5-.6-3.6-.6h-10.1v6.6h4.9v-2.8h.1Z"/><rect x="49.2" y="55.9" width="4.9" height="4.6"/><path d="M59.4,52.5q2.1,0,3.6-.6c1-.4,1.8-.9,2.4-1.6.6-.7,1.1-1.4,1.4-2.3.3-.9.4-1.8.4-2.7s0-.4,0-.6h-4.8v.6c0,.7-.1,1.3-.4,1.7-.2.4-.6.8-1,1s-.9.4-1.4.5c-.5,0-1.1.1-1.6.1h-3.8v-3.9h-4.9v11.2h4.9v-3.4H59.4Z" fill="#F4EEE4"/><polygon points="68.6 60.6 85.7 60.6 85.7 56.4 73.6 56.4 73.6 55.9 68.6 55.9 68.6 60.6"/><polygon points="73.6 42.3 85.5 42.3 85.5 38.1 68.6 38.1 68.6 44.8 73.6 44.8 73.6 42.3"/><polygon points="84.5 50.9 84.5 47.1 73.6 47.1 73.6 44.8 68.6 44.8 68.6 55.9 73.6 55.9 73.6 50.9 84.5 50.9" fill="#F4EEE4"/><rect x="92.9" y="38.1" width="4" height="4.2"/><rect x="99.6" y="44.8" width="4.9" height="11.2"/><polygon points="99.6 42.3 99.6 44.8 104.5 44.8 104.5 42.3 111.3 42.3 111.3 38.1 96.9 38.1 96.9 42.3 99.6 42.3" fill="#F4EEE4"/><rect x="99.6" y="56" width="4.9" height="4.6" fill="#F4EEE4"/><path d="M111.3,49.4c0,1.6.3,3.2.8,4.6.3.7.6,1.4.9,2h6.1c-.6-.4-1.1-.8-1.4-1.4-.5-.7-.8-1.5-1.1-2.4-.2-.9-.3-1.8-.3-2.8s.1-1.9.3-2.9c.2-.7.4-1.3.7-1.8h-5.2c-.5,1.4-.8,3-.8,4.7h0Z"/><path d="M128.1,46.6c.2.9.3,1.9.3,2.9s-.1,1.9-.3,2.8c-.2.9-.6,1.7-1.1,2.4-.4.6-.9,1-1.4,1.4h6.1c.4-.6.7-1.3.9-2,.5-1.4.8-2.9.8-4.6s-.3-3.2-.8-4.7h-5.2c.3.6.5,1.2.7,1.8h0Z"/><path d="M117.6,44.1c.5-.7,1.1-1.3,1.9-1.7.8-.4,1.7-.6,2.8-.6s2.1.2,2.8.6c.8.4,1.4,1,1.9,1.7q.15.3.3.6h5.2c-.5-1.4-1.2-2.7-2.2-3.7-.9-1.1-2.1-1.9-3.5-2.5s-2.9-.9-4.7-.9-3.3.3-4.6.9c-1.4.6-2.5,1.4-3.5,2.5-.9,1.1-1.7,2.3-2.2,3.7h5.2q.15-.3.3-.6H117.6Z" fill="#F4EEE4"/><path d="M125.2,56.3c-.8.4-1.7.6-2.8.6s-2.1-.2-2.8-.6c-.2,0-.3-.2-.4-.3h-6.1c.4.6.8,1.2,1.3,1.7.9,1,2.1,1.9,3.5,2.5s2.9.9,4.6.9,3.3-.3,4.7-.9c1.4-.6,2.5-1.4,3.5-2.5.5-.5.9-1.1,1.3-1.7h-6.1c-.2.1-.3.2-.4.3H125.2Z" fill="#F4EEE4"/><polygon points="151.7 60.6 156.6 60.6 157.8 55.9 150.5 55.9 151.7 60.6"/><polygon points="143.9 44.8 141.7 53.4 141.6 53.4 139.6 44.8 134.9 44.8 137.9 56 145.2 56 145.3 55.6 145.3 44.8 143.9 44.8"/><polygon points="162.6 38.1 157.8 38.1 156.2 44.8 160.8 44.8 162.6 38.1"/><polygon points="151.9 44.8 150.2 38.1 145.6 38.1 145.3 39.3 145.3 44.8 151.9 44.8"/><polygon points="145.3 44.8 145.3 39.3 143.9 44.8 145.3 44.8" fill="#F4EEE4"/><polygon points="138.1 38.1 133.1 38.1 134.9 44.8 139.6 44.8 138.1 38.1" fill="#F4EEE4"/><polygon points="139.1 60.6 144.1 60.6 145.2 56 137.9 56 139.1 60.6" fill="#F4EEE4"/><polygon points="145.3 55.6 147.8 45.3 147.9 45.3 150.5 55.9 157.8 55.9 160.8 44.8 156.2 44.8 154.1 53.6 154 53.6 151.9 44.8 145.3 44.8 145.3 55.6" fill="#F4EEE4"/><polygon points="168.2 38.1 163.3 38.1 163.3 44.8 172.4 44.8 168.2 38.1"/><rect x="177.7" y="38.1" width="4.6" height="6.6"/><polygon points="177.4 60.6 182.3 60.6 182.3 55.9 174.5 55.9 177.4 60.6"/><rect x="163.3" y="55.9" width="4.6" height="4.6"/><polygon points="167.9 55.9 167.9 45.5 168 45.5 174.5 55.9 182.3 55.9 182.3 44.8 177.7 44.8 177.7 53.2 177.6 53.2 172.4 44.8 163.3 44.8 163.3 55.9 167.9 55.9" fill="#F4EEE4"/></svg><b id="sEvents" data-count>19</b></div><div class="stat__l">races &amp; rides</div></div>
</section>
<!--/cc:stats-->

<section class="sect" id="deal" aria-labelledby="dealT">
  <div class="in max"><h2 class="h" id="dealT">The deal, between friends</h2><p class="sub">We keep the bikes in shape. You bring them back the way you got them. Who pays?</p></div>
  <div class="deal max">
<!--cc:game-->
    <div class="game" id="game" aria-live="polite">
      <div class="meta"><span class="mono">Who pays?</span><span class="pips" id="pips"></span></div>
      <p class="q" id="gq"></p>
      <div class="ans"><button class="pillbtn" id="aUs">Community Connect</button><button class="pillbtn" id="aYou">The rider</button></div>
      <p class="why" id="gw"></p>
      <div class="meta"><span id="gs" class="mono"></span><button class="pillbtn ink sm" id="gn">Next</button></div>
    </div>
<!--/cc:game-->
    <details><summary>We cover</summary><ul><li>Regular servicing with Woodstock Cycleworks</li><li>Normal wear: chain, tyres, brake pads</li><li>A puncture on a long day out</li><li>Finding you a bike as often as we can</li></ul></details>
    <details><summary>You cover</summary><ul><li>Breaks from reckless riding or the wrong kind of use</li><li>Damage while racing, crashes included</li><li>A wash &amp; lube if it comes back dirty</li><li>Telling us early, and flagging damage at the handover</li></ul></details>
  </div>
</section>

<section class="sect" id="ask" aria-labelledby="askT">
  <div class="in max"><h2 class="h" id="askT">Any bike will do?</h2><p class="sub">Ask for any bike in your size. We pick one that’s free on your dates.</p></div>
  <div class="ask max"><?php echo do_shortcode( '[cc_bike_request]' ); // phpcs:ignore ?></div>
</section>

<section class="drawings max" aria-label="Drawings by Kooooos">
  <figure><div class="art" style="background:var(--pink)"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/bear.webp" alt="Hand-drawn bear waving a flag, with the Standert lettering" loading="lazy"></div><figcaption>Gravel Hearts Club</figcaption></figure>
  <figure><div class="art" style="background:var(--sand)"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/eatmydust.webp" alt="Hand-lettered Eat my Dust with a poodle" loading="lazy"></div><figcaption>Eat my Dust</figcaption></figure>
</section>

<section class="sect" id="agreement" aria-labelledby="agT">
  <div class="in max"><h2 class="h" id="agT">The agreement</h2><p class="sub">Not legal small print. How friends share something they love.</p></div>
  <div class="agree max"><?php echo CC_Fleet_Landing::agreement(); // phpcs:ignore ?></div>
</section>

<a class="photo" href="https://www.instagram.com/cc_x_standert/" style="margin-top:28px"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/journal.webp" alt="The workshop, with a frame on the table and drawings on the wall" loading="lazy"><span class="ov"><b>After the ride</b><span class="l2">Tell us what the bike let you do.</span><span class="hand">@cc_x_standert</span></span></a>
</main>

<footer class="foot">
  <div class="max">
    <p>Community Connect NPC<br>Woodstock, Cape Town, ZA</p>
    <p>Group rides: weekly, on Strava<br>Questions: <a href="mailto:contact@community-connect.cc">contact@community-connect.cc</a></p>
    <details><summary>The collective</summary><a href="#how">How it works</a><a href="#deal">The deal</a><a href="#agreement">Borrowing agreement</a></details>
    <details><summary>Happenings</summary><a href="#events">Events 2027</a><a href="https://www.instagram.com/cc_x_standert/">Instagram</a></details>
    <details><summary>Bikes &amp; support</summary><a href="#fleet">The fleet</a><a href="#ask">Ask for any bike</a><a href="mailto:contact@community-connect.cc">Email the crew</a></details>
    <div class="soc"><a class="ibtn" href="https://www.instagram.com/cc_x_standert/" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1" fill="currentColor" stroke="none"/></svg></a><a class="ibtn" href="mailto:contact@community-connect.cc" aria-label="Email"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6 8.5 7 8.5-7"/></svg></a></div>
    <p class="fine"><a href="#agreement">Borrowing agreement</a> · bikes maintained with Woodstock Cycleworks</p>
    <p class="fine">© <span id="yr">2026</span> Community Connect NPC · Reg. 2025/713989/08</p>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
