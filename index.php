<?php

declare(strict_types=1);

require_once __DIR__ . '/order/_bootstrap.php';

$menu = (new MenuRepository())->load();
$subcategoriesById = $menu->subcategoriesById();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Marvel Patisserie &amp; Cafe</title>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="order-csrf-token" content="<?= e(order_csrf_token()) ?>">
  <meta name="keywords" content="Marvel Patisserie, Marvel Cafe, desserts, coffee, Itay Elbaroud">
  <meta name="description" content="Marvel Patisserie &amp; Cafe in Itay Elbaroud offers elegant desserts, artisan coffee, and online ordering in a premium patisserie atmosphere.">

  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('main.css')) ?>">
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('style.css')) ?>">
  <link rel="shortcut icon" href="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-logo-mark-transparent.png')) ?>" type="image/png">
  <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.10.0/css/all.css">
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('assets/vendor/lenis/lenis.css')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body id="top">
  <header
    class="hero-section"
    id="story"
    style="--hero-image: url('<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-storefront.jpg')) ?>')"
  >
    <nav aria-label="Primary">
      <a class="logo" href="#top" aria-label="Marvel Patisserie home">
        <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-logo-mark-transparent.png')) ?>" alt="Marvel Patisserie logo">
        <span class="logo-copy">
          <strong>Marvel</strong>
          <span>Patisserie &amp; Cafe</span>
        </span>
      </a>

      <ul>
        <li><a href="#story">Story</a></li>
        <li><a href="#menu">Menu</a></li>
        <li><a href="#contact">Contact</a></li>
        <li class="cart-nav">
          <button id="cart-toggle" type="button" aria-expanded="false" aria-controls="cart-panel" title="Open cart">
            <i class="fal fa-shopping-cart cart" aria-hidden="true"></i>
            <span>Cart</span>
            <span class="cart-count" aria-live="polite">0</span>
          </button>
          <div class="cart-dropdown" id="cart-panel" role="dialog" aria-label="Shopping Cart">
            <h4>Marvel Cart</h4>
            <ul class="cart-list"></ul>
            <p class="cart-empty">Cart is empty</p>
            <div class="cart-total">Total: LE 0.00</div>
            <div class="cart-actions">
              <button class="cart-clear" aria-label="Clear cart">Clear</button>
              <button class="cart-checkout" aria-label="Continue to order">Continue to order</button>
            </div>
          </div>
        </li>
      </ul>
    </nav>

    <div class="hero-shell">
      <div class="hero-text animate-on-scroll">
        <span class="hero-kicker">Marvel Patisserie &amp; Cafe</span>
        <h1>Elegant desserts, artisan coffee, and signature cakes for Itay Elbaroud.</h1>
        <p>Marvel Patisserie &amp; Cafe brings desserts, cakes, and coffee together in a polished ordering experience designed for quick browsing and easy ordering.</p>
        <div class="hero-actions">
          <a href="#menu" class="hero-link hero-link-primary">Explore the menu</a>
          <a href="#contact" class="hero-link hero-link-secondary">Contact Us</a>
        </div>
      </div>

      <aside class="hero-brand-card animate-on-scroll" aria-label="Marvel signature selection">
        <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-logo-full-transparent.png')) ?>" alt="Marvel Patisserie brand card">
        <div class="hero-brand-copy">
          <span class="hero-brand-label">Signature Selection</span>
          <strong>Marvel</strong>
          <p>Patisserie, desserts, cakes, and coffee with a refined everyday menu.</p>
        </div>
      </aside>
    </div>
  </header>

  <section class="brand-overview animate-on-scroll" aria-label="Marvel storefront">
    <div class="brand-overview-media">
      <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-storefront.jpg')) ?>" alt="Marvel Patisserie &amp; Cafe storefront">
    </div>
    <div class="brand-overview-copy">
      <span class="section-eyebrow">The Space</span>
      <h2>A refined patisserie experience from first glance to final order.</h2>
      <p>Deep navy tones, warm gold accents, and cleaner spacing keep the focus on desserts, coffee, and a smoother ordering journey.</p>
      <div class="brand-overview-tags">
        <span>Signature desserts</span>
        <span>Artisan coffee</span>
        <span>Patisserie service</span>
      </div>
    </div>
  </section>

  <section class="section service bg-black-10 text-center brand-showcase" aria-label="Marvel highlights">
    <div class="container">
      <p class="section-subtitle label-2">Marvel Highlights</p>
      <h2 class="headline-1 section-title">A premium selection curated for every sweet break</h2>
      <p class="section-text">Desserts, cakes, and coffee moments chosen to reflect the Marvel Patisserie &amp; Cafe experience.</p>

      <ul class="grid-list">
        <li>
          <div class="service-card">
            <div class="has-before hover:shine">
              <figure class="card-banner img-holder" style="--width: 285; --height: 336;">
                <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-french-toast.jpg')) ?>" width="285" height="336" loading="lazy" alt="Dessert plate" class="img-cover">
              </figure>
            </div>

            <div class="card-content">
              <h3 class="title-4 card-title"><a href="#menu">Dessert Plates</a></h3>
              <a href="#menu" class="btn-text hover-underline label-2">Discover</a>
            </div>
          </div>
        </li>

        <li>
          <div class="service-card">
            <div class="has-before hover:shine">
              <figure class="card-banner img-holder" style="--width: 285; --height: 336;">
                <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-signature-cake.jpg')) ?>" width="285" height="336" loading="lazy" alt="Signature cake" class="img-cover">
              </figure>
            </div>

            <div class="card-content">
              <h3 class="title-4 card-title"><a href="#menu">Signature Cakes</a></h3>
              <a href="#menu" class="btn-text hover-underline label-2">Discover</a>
            </div>
          </div>
        </li>

        <li>
          <div class="service-card">
            <div class="has-before hover:shine">
              <figure class="card-banner img-holder" style="--width: 285; --height: 336;">
                <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-coffee.jpg')) ?>" width="285" height="336" loading="lazy" alt="Coffee selection" class="img-cover">
              </figure>
            </div>

            <div class="card-content">
              <h3 class="title-4 card-title"><a href="#menu">Coffee Rituals</a></h3>
              <a href="#menu" class="btn-text hover-underline label-2">Discover</a>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </section>

  <main>
    <section id="menu" class="menu-section">
      <div class="section-heading animate-on-scroll">
        <span class="section-eyebrow">Order Online</span>
        <h2>Marvel Menu</h2>
        <p>Browse the full menu and place your order online in a few quick steps.</p>
      </div>

      <div class="menu-filters animate-on-scroll">
        <?php foreach (($menu->filters ?? []) as $filter): ?>
          <?php
            $filterId = (string) ($filter->id ?? '');
            $filterLabel = (string) ($filter->label ?? '');
            $isAll = ($filterId === 'all');
          ?>
          <button class="filter-btn<?= $isAll ? ' active' : '' ?>" data-filter="<?= e($filterId) ?>"><?= e($filterLabel) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="menu-grid">
        <?php foreach (($menu->filters ?? []) as $filter): ?>
          <?php
            $subcategoryId = (string) ($filter->id ?? '');
            if ($subcategoryId === '' || $subcategoryId === 'all') {
              continue;
            }
            $subcategory = $subcategoriesById[$subcategoryId] ?? null;
            if (!$subcategory) {
              continue;
            }
          ?>
          <?php foreach (($subcategory->items ?? []) as $item): ?>
            <?php
              $imageUrl = (string) ($item->image_url ?? '');
              $nameAr = (string) (($item->name->ar ?? '') ?? '');
              $nameEn = (string) (($item->name->en ?? '') ?? '');
              $isOutOfStock = (bool) ($item->is_out_of_stock ?? false);
              $sizes = $item->sizes ?? null;
              $hasSizes = is_array($sizes) && count($sizes) > 0;
              $basePrice = (string) ($item->price ?? 0);
              $defaultSizePrice = $hasSizes ? (string) (($sizes[0]->price ?? null) ?? 0) : $basePrice;
            ?>
            <div class="menu-item animate-on-scroll<?= $isOutOfStock ? ' menu-item-out-of-stock' : '' ?>" data-category="<?= e($subcategoryId) ?>" data-item-id="<?= e((string) $item->id) ?>" data-out-of-stock="<?= $isOutOfStock ? '1' : '0' ?>">
              <?php if ($isOutOfStock): ?>
                <span class="menu-item-badge">Out of stock</span>
              <?php endif; ?>
              <img src="<?= e($imageUrl) ?>" alt="" class="menu-item-img">
              <div class="menu-item-content">
                <h3><?= e($nameAr) ?></h3>
                <h3><?= e($nameEn) ?></h3>
                <?php if ($hasSizes): ?>
                  <div class="menu-item-sizes">
                    <select class="size-select" aria-label="Select size"<?= $isOutOfStock ? ' disabled' : '' ?>>
                      <?php foreach ($sizes as $size): ?>
                        <?php
                          $sizeNameAr = (string) (($size->name->ar ?? '') ?? '');
                          $sizeNameEn = (string) (($size->name->en ?? '') ?? '');
                          $sizePriceText = (string) (($size->price ?? null) ?? 0);
                          if ($sizeNameAr !== '' && $sizeNameEn !== '' && $sizeNameAr !== $sizeNameEn) {
                            $sizeLabel = $sizeNameAr . ' / ' . $sizeNameEn;
                          } else {
                            $sizeLabel = $sizeNameEn !== '' ? $sizeNameEn : $sizeNameAr;
                          }
                        ?>
                        <option value="<?= e($sizePriceText) ?>" data-size-id="<?= e((string) $size->id) ?>" data-size-ar="<?= e($sizeNameAr) ?>" data-size-en="<?= e($sizeNameEn) ?>">
                          <?= e($sizeLabel) ?> (LE <?= e($sizePriceText) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <div class="menu-item-footer">
                  <span class="price" data-price="<?= e($defaultSizePrice) ?>">LE <?= e($defaultSizePrice) ?></span>
                  <button class="add-to-cart-btn" type="button"<?= $isOutOfStock ? ' disabled aria-disabled="true"' : '' ?>><?= $isOutOfStock ? 'Out of stock' : '+' ?></button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <footer id="contact" class="site-footer animate-on-scroll">
    <div class="footer-content">
      <img class="footer-logo" src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-logo-full-transparent.png')) ?>" alt="Marvel Patisserie logo">
      <h2 class="logo">Marvel Patisserie &amp; Cafe</h2>
      <p class="footer-location">Itay Elbaroud</p>
      <p class="footer-note">Follow Marvel and stay close to the latest desserts, cakes, and coffee.</p>

      <div class="social-links">
        <a href="https://api.whatsapp.com/send/?phone=%2B201508803316&text&type=phone_number&app_absent=0" target="_blank" rel="noopener noreferrer" class="social-btn" aria-label="WhatsApp">
          <i class="fab fa-whatsapp"></i>
        </a>
        <a href="https://www.facebook.com/MarvelItay?mibextid=ZbWKwL" target="_blank" rel="noopener noreferrer" class="social-btn" aria-label="Facebook">
          <i class="fab fa-facebook-f"></i>
        </a>
      </div>

      <p class="copyright">
        Designed &amp; Developed by
        <a href="https://www.facebook.com/NextGen555" target="_blank" rel="noopener noreferrer" class="animated-name">NextGen@Mostafa Elkellawey</a>
      </p>
    </div>
  </footer>

  <script src="<?= e(HttpCache::versionedAssetUrl('script.js')) ?>"></script>
  <script src="<?= e(HttpCache::versionedAssetUrl('assets/vendor/lenis/lenis.min.js')) ?>"></script>
  <script type="module" src="<?= e(HttpCache::versionedAssetUrl('assets/vendor/ionicons/ionicons.esm.js')) ?>"></script>

  <a href="#top" class="back-top-btn active" aria-label="back to top" data-back-top-btn>
    <ion-icon name="chevron-up" aria-hidden="true"></ion-icon>
  </a>
</body>
</html>
