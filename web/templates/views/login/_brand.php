<?php /* The logo and name on the sign-in pages. */ ?>
        <div class="u-text-center u-mb-24">
            <?php if (!empty($site_logo_wide)): ?>
            <div class="brand-wide-login">
                <img src="<?php echo htmlspecialchars($site_logo_image); ?>" alt="<?php echo htmlspecialchars($brand_title); ?>" class="<?php echo !empty($site_logo_image_dark) ? 'brand-logo-light' : ''; ?>">
                <?php if (!empty($site_logo_image_dark)): ?><img src="<?php echo htmlspecialchars($site_logo_image_dark); ?>" alt="<?php echo htmlspecialchars($brand_title); ?>" class="brand-logo-dark"><?php endif; ?>
            </div>
            <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_sub); ?></p>
            <?php else: ?>
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <?php if ($site_logo_type === 'image' && !empty($site_logo_image)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo_image); ?>" alt="Logo" class="<?php echo !empty($site_logo_image_dark) ? 'brand-logo-light' : ''; ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php if (!empty($site_logo_image_dark)): ?><img src="<?php echo htmlspecialchars($site_logo_image_dark); ?>" alt="Logo" class="brand-logo-dark" style="max-width: 100%; max-height: 100%; object-fit: contain;"><?php endif; ?>
                <?php else: ?>
                    <i class="fas <?php echo htmlspecialchars($site_logo_icon); ?> u-primary"></i>
                <?php endif; ?>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6"><?php echo htmlspecialchars($brand_sub); ?></p>
            <?php endif; ?>
        </div>

