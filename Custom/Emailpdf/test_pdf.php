<?php
/**
 * CLI Test Script for Custom_Emailpdf
 *
 * Run from Magento root:
 *   php app/code/Custom/Emailpdf/test_pdf.php
 *
 * Edit the variables in the CONFIG section below before running.
 */

// ============================================================
//  CONFIG — edit these before running
// ============================================================
$testOrderIncrementId = '000000002';     // An existing order increment ID in your store
$overrideEmail        = 'dx4oolbhv5@bwmyga.com'; // Where to send the test email (your inbox)
$savePdfToDisk        = true;            // true = also save PDF to var/custom_emailpdf_test.pdf
// ============================================================

use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../bootstrap.php'; // resolves to app/bootstrap.php from Magento root

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om        = $bootstrap->getObjectManager();

// Set area so config / payment method titles load correctly
/** @var \Magento\Framework\App\State $state */
$state = $om->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Exception $e) { /* already set */ }

// Load order by increment ID
/** @var \Magento\Framework\Api\SearchCriteriaBuilder $scb */
$scb = $om->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
$scb->addFilter('increment_id', $testOrderIncrementId);

/** @var \Magento\Sales\Api\OrderRepositoryInterface $orderRepo */
$orderRepo = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
$items     = $orderRepo->getList($scb->create())->getItems();

if (empty($items)) {
    echo "[ERROR] Order #{$testOrderIncrementId} not found. Check the increment ID.\n";
    exit(1);
}

$order = reset($items);
echo "[OK] Loaded order #{$order->getIncrementId()} — {$order->getCustomerEmail()}\n";

// Build the observer and fire it manually
/** @var \Custom\Emailpdf\Observer\SendOrderPdf $observer */
$observer = $om->create(\Custom\Emailpdf\Observer\SendOrderPdf::class);

// Use reflection to call the private buildPdf() method directly for disk save test
if ($savePdfToDisk) {
    $ref    = new \ReflectionClass($observer);
    $method = $ref->getMethod('buildPdf');
    $method->setAccessible(true);
    $pdfBytes = $method->invoke($observer, $order);
    $outPath  = BP . '/var/custom_emailpdf_test.pdf';
    file_put_contents($outPath, $pdfBytes);
    echo "[OK] PDF saved to: {$outPath}\n";
    echo "     Open it to verify layout before testing email.\n";
}

// Now fire the full observer (generates PDF + sends email)
// Temporarily override the recipient by using OVERRIDE_RECIPIENT logic:
// We do this by injecting a custom ScopeConfig mock... actually easier:
// We just manipulate the event object and rely on OVERRIDE_RECIPIENT constant.
// --> For a quick test, just set OVERRIDE_RECIPIENT constant in SendOrderPdf.php
//     to $overrideEmail and TEST_MODE to false, then run this script.

echo "\n[INFO] To send the email, edit SendOrderPdf.php:\n";
echo "       Set OVERRIDE_RECIPIENT = '{$overrideEmail}'\n";
echo "       Then re-run this script — it will trigger the real email flow.\n";

// Build a fake observer event and call execute()
$event       = new \Magento\Framework\Event(['order' => $order]);
$observerObj = new \Magento\Framework\Event\Observer();
$observerObj->setEvent($event);

echo "\n[RUNNING] Firing observer->execute() ...\n";
$observer->execute($observerObj);
echo "[DONE] Check var/log/custom_emailpdf.log for result.\n";
echo "       If no error there, the email was dispatched via Magento transport.\n";
echo "       Check your SMTP / mail config if you don't receive it.\n";
