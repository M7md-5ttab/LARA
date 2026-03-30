<?php

declare(strict_types=1);

require_once __DIR__ . '/order/_bootstrap.php';

$menu = (new MenuRepository())->load();
$subcategoriesById = $menu->subcategoriesById();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>LARA</title>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="order-csrf-token" content="<?= e(order_csrf_token()) ?>">
  <meta name="keywords" content="Coffee Shop">
  <meta name="description" content="Discover our cafe & restaurant menu with fresh coffee, delicious meals, online ordering, and table reservations in a cozy atmosphere.">

  <!-- Styles -->
    <link rel="stylesheet" href="main.css">
    <link rel="stylesheet" href="style.css">

    <link rel="shortcut icon" href="img/Untitled design.png" type="image/x-icon">
  <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.10.0/css/all.css"/>
  <link rel="stylesheet" href="https://unpkg.com/lenis@1.3.11/dist/lenis.css">
  
  <!-- Fonts -->
  <!-- <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Lora:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet"> -->
  <!-- 
    - google font link
  -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;700&family=Forum&display=swap" rel="stylesheet">


</head>
<body id="top">

  <!-- Header & Navigation -->
  <header class="hero-section">
    <nav aria-label="Primary">
      <div class="logo"> <img src="img/Untitled design.png" alt=""> </div>
      <!-- <img src="image/ال.png" alt=""> -->
      <ul>
        <li><a href="#menu">Menu</a></li>
        <li><a href="#contact">Contact</a></li>
        <li class="cart-nav">
          <button id="cart-toggle" aria-expanded="false" aria-controls="cart-panel" title="Open cart">
            <i class="fal fa-shopping-cart cart" aria-hidden="true"></i>
            <span class="cart-count" aria-live="polite">0</span>
          </button>
          <div class="cart-dropdown" id="cart-panel" role="dialog" aria-label="Shopping Cart">
            <h4>Your Cart</h4>
            <ul class="cart-list"></ul>
            <p class="cart-empty">Cart is empty</p>
            <div class="cart-total">Total:  LE  0.00</div>
            <div class="cart-actions">
              <button class="cart-clear" aria-label="Clear cart">Clear</button>
              <button class="cart-checkout" aria-label="Continue to order">Continue to order</button>
            </div>
          </div>
        </li>
      </ul>
    </nav>

    <div class="hero-text">
      <h1>LARA Coffee & lounge</h1>
      <p>Your daily dose of perfection, brewed with passion.</p>
    </div>
  </header>



      <section class="section service bg-black-10 text-center" aria-label="service">
        <div class="container">

          <p class="section-subtitle label-2">Flavors For Royalty</p>

          <h2 class="headline-1 section-title">We Offer Top Notch</h2>

          <p class="section-text"></p>

          <ul class="grid-list">

            <li>
              <div class="service-card">

                <div class="has-before hover:shine">
                  <figure class="card-banner img-holder" style="--width: 285; --height: 336;">
                    <img src="./img/WhatsApp Image 2026-01-09 at 1.27.06 PM.jpeg" width="285" height="336" loading="lazy" alt="Breakfast"
                      class="img-cover">
                  </figure>
                </div>

                <div class="card-content">

                  <h3 class="title-4 card-title">
                    <a href="">Breakfast</a>
                  </h3>

                  <a href="#menu" class="btn-text hover-underline label-2">View Menu</a>

                </div>

              </div>
            </li>

            <li>
              <div class="service-card">

                <div class="has-before hover:shine">
                  <figure class="card-banner img-holder" style="--width: 285; --height: 336;">
                    <img src="./img/WhatsApp Image 2026-01-09xx at 1.27.10 PM.jpeg" width="285" height="336" loading="lazy" alt="Appetizers"
                      class="img-cover">
                  </figure>
                </div>

                <div class="card-content">

                  <h3 class="title-4 card-title">
                    <a href="">Appetizers</a>
                  </h3>

                  <a href="#menu" class="btn-text hover-underline label-2">View Menu</a>

                </div>

              </div>
            </li>

            <li>
              <div class="service-card">

                <div class="has-before hover:shine">
                  <figure class="card-banner img-holder" style="--width: 285; --height: 336;">
                    <img src="./img/WhatsApp Ismage 2026-01-09 at 1.27.09 PM.jpeg" width="285" height="336" loading="lazy" alt="Drinks"
                      class="img-cover">
                  </figure>
                </div>

                <div class="card-content">

                  <h3 class="title-4 card-title">
                    <a href="">Drinks</a>
                  </h3>

                  <a href="#menu" class="btn-text hover-underline label-2">View Menu</a>

                </div>

              </div>
            </li>

          </ul>

          <img src="./assets/images/shape-1.png" width="246" height="412" loading="lazy" alt="shape"
            class="shape shape-1 move-anim">
          <img src="./assets/images/shape-2.png" width="343" height="345" loading="lazy" alt="shape"
            class="shape shape-2 move-anim">

        </div>
      </section>




  <!-- Main Content -->
    <main>
        <!-- Menu Section -->
        <section id="menu" class="menu-section">
            <h2 class="animate-on-scroll">Our Menu</h2>

            <!-- Filters -->
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

            <!-- Menu Grid -->
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
                                  $sizeLabel = '';
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
                          <span class="price" data-price="<?= e($defaultSizePrice) ?>"> LE  <?= e($defaultSizePrice) ?></span>
                          <button class="add-to-cart-btn" type="button"<?= $isOutOfStock ? ' disabled aria-disabled="true"' : '' ?>><?= $isOutOfStock ? 'Out of stock' : '+' ?></button>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <!-- Footer -->
   <footer id="contact" class="site-footer animate-on-scroll">
    <div class="footer-content">
      <h2 class="logo">LARA Menu</h2>
      <p>Itay Elbaroud</p>

      <!-- Social Links -->
      <div class="social-links">
            <a href="https://www.instagram.com/laracafeofficial?igsh=bjFoZW00bXg2eWs4" target="_blank" class="social-btn"><i class="fab fa-instagram"></i></a>
            <a href="https://api.whatsapp.com/send/?phone=%2B201508803316&text&type=phone_number&app_absent=0" target="_blank" class="social-btn"><i class="fab fa-whatsapp"></i></a>
            <a href="https://www.facebook.com/share/17imBTUjdp/?mibextid=wwXIfr" target="_blank" class="social-btn"><i class="fab fa-facebook-f"></i></a>
            <a href="https://www.tiktok.com/@laracoffeeandlounge?_r=1&_d=ehklaichm6f4cj&sec_uid=MS4wLjABAAAAOSDlcrJWRsH53ltQkKXbahgTKnl4NdTBCErtq2NNj-CmS7eEH7gbGQqgy68wg6Ox&share_author_id=7498871143430063159&sharer_language=en&source=h5_m&u_code=die2ga38kja90f&item_author_type=2&utm_source=copy&tt_from=copy&enable_checksum=1&utm_medium=ios&share_link_id=68934C32-D482-42BB-A08D-EE8F91F3B7D2&user_id=6960387747669033989&sec_user_id=MS4wLjABAAAAyYbz2MrKr99EZfrL6JwajVVPtRE0yflRLkNeQ_mDPh4JyPtil3dflImfvyVTNlC-&social_share_type=5&ug_btm=b5836,b0&utm_campaign=client_share&share_app_id=1233" target="_blank" class="social-btn"><i class="bi bi-tiktok"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-tiktok" viewBox="0 0 16 16">
  <path d="M9 0h1.98c.144.715.54 1.617 1.235 2.512C12.895 3.389 13.797 4 15 4v2c-1.753 0-3.07-.814-4-1.829V11a5 5 0 1 1-5-5v2a3 3 0 1 0 3 3z"/>
</svg></i></a>
            <!-- <a href="https://" target="_blank" class="social-btn"><i class="fas fa-globe"></i></a> -->
        </div>

    <p class="copyright">
  Designed & Developed by 
  <a href="https://www.facebook.com/NextGen555" target="_blank" class="animated-name">NextGen@Mostafa Elkellawey</a>
    </p>
    </div>
  </footer>

  <!-- Scripts -->
  <script src="script.js"></script>
  <script src="https://unpkg.com/lenis@1.3.11/dist/lenis.min.js"></script> 

  <!-- (Removed unused template script that relied on missing data-* markup) -->

  <!-- 
    - ionicon link
  -->
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <!-- 
    - #BACK TO TOP
  -->

  <a href="#top" class="back-top-btn active" aria-label="back to top" data-back-top-btn>
    <ion-icon name="chevron-up" aria-hidden="true"></ion-icon>
  </a>



</body>
</html>
