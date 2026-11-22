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
    <a class="slide" href="#fleet" aria-label="Borrow a bike: free, then pass it on"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/hero-ghc.webp" alt="A pink Standert gravel bike hanging in a sunlit workshop"><span class="ov"><span class="tag">Free to borrow</span><b>Borrow a premium bike</b><span class="l2">Pass it on.</span><span class="hand">10 standerts, 0 fees x</span></span></a>
    <a class="slide" href="#fleet" aria-label="Gravel Hearts Club: two bikes repainted"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/repaint.webp" alt="A frame being repainted pink by hand"><span class="ov"><span class="tag">New project</span><b>Gravel Hearts Club</b><span class="l2">Cute and Dirty</span><span class="hand">2 bikes, repainted x</span></span></a>
    <a class="slide" href="#events" aria-label="Events 2027: race it on a fleet bike"><img src="<?php echo esc_url( CC_FLEET_URL . 'assets/landing/img' ); ?>/desolation.webp" alt="A rider carrying a frame, motion blurred"><span class="ov"><span class="tag">Events 2027</span><b>Race it on a fleet bike</b><span class="l2">Gravel races, dates out.</span><span class="hand">racing terms apply x</span></span></a>
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
    <form class="when" id="dateForm"><label for="d1">From<input id="d1" type="date" required></label><label for="d2">Until<input id="d2" type="date" required></label><button class="pillbtn ink" type="submit">Check</button></form>
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

<!--cc:stats--><section class="stats max" aria-label="In numbers">
  <div><b id="sLoans">115</b><span>loans in <span id="sYear">2026</span></span></div>
  <div><b id="sRiders">102</b><span>Connectors</span></div>
  <div><b id="sEvents">19</b><span>races &amp; events</span></div>
</section><!--/cc:stats-->

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
