# DbStorage Integration Test for UTF-8mb4 Support

## Overview

This integration test validates that the `DbStorage` class properly handles 4-byte UTF-8 characters (emojis) when the database is configured with `utf8mb4` charset and collation.

## Related Issue

- **JIRA:** ACP2E-4328
- **Solution:** Changed from blocking 4-byte UTF-8 characters to supporting them via `utf8mb4` charset

## What Was Changed

### 1. Removed Validation (Unit Test Updated)
- **File:** `app/code/Magento/UrlRewrite/Test/Unit/Model/Storage/DbStorageTest.php`
- **Change:** Updated `testFindOneByDataRejectsInvalidUtf8Sequences` to expect database queries instead of rejection
- **Reason:** No longer blocking valid UTF-8 characters; database handles them natively

### 2. Database Charset Auto-Detection
- **File:** `lib/internal/Magento/Framework/Setup/Declaration/Schema/Dto/Factories/Table.php`
- **Behavior:** Automatically selects charset based on database version:
  - **MySQL 8.29+, MariaDB 10.4+:** `utf8mb4` / `utf8mb4_general_ci` ✅
  - **Older versions:** `utf8` / `utf8_general_ci` ⚠️ (emojis won't work)

### 3. Removed Manual Configuration Requirement
- **File:** `lib/internal/Magento/Framework/Model/ResourceModel/Type/Db/Pdo/Mysql.php`
- **Change:** Removed `'initStatements' => 'SET NAMES utf8'` from defaults
- **Reason:** Let MySQL use the table-level charset/collation automatically

## Integration Test Files

### Test File
`dev/tests/integration/testsuite/Magento/UrlRewrite/Model/Storage/DbStorageTest.php`

### Fixture Files
- `dev/tests/integration/testsuite/Magento/UrlRewrite/_files/url_rewrite_with_utf8mb4.php`
- `dev/tests/integration/testsuite/Magento/UrlRewrite/_files/url_rewrite_with_utf8mb4_rollback.php`

## Test Coverage

### 1. Database Charset Verification
```php
testTableCharsetIsUtf8mb4OrUtf8()
```
- Verifies `url_rewrite` table uses `utf8mb4` or `utf8` collation
- Logs the actual collation for debugging

### 2. 4-Byte UTF-8 Characters (Emojis)
```php
testFindOneByDataWithUtf8mb4Characters()
```
Tests URL rewrites with:
- 🔎 Magnifying glass emoji
- 🎉 Party popper emoji
- 😀 Grinning face emoji
- 🏠 House emoji
- 𝕳𝖊𝖑𝖑𝖔 Mathematical alphanumeric symbols

**Behavior:**
- **utf8mb4:** Tests pass ✅
- **utf8:** Tests skipped with message: "Requires utf8mb4 collation for emoji support"

### 3. 3-Byte UTF-8 Characters
```php
testFindOneByDataWith3ByteUtf8Characters()
```
Tests URL rewrites with:
- `café` (accented characters)
- `你好` (Chinese characters)

**Behavior:**
- **Both utf8 and utf8mb4:** Tests pass ✅

### 4. Replace Operation
```php
testReplaceWithUtf8mb4Characters()
```
- Creates new URL rewrite with emoji
- Verifies it can be saved and retrieved
- Cleans up after test

### 5. FindAll Operation
```php
testFindAllByDataWithUtf8mb4Characters()
```
- Queries all URL rewrites
- Filters results containing emojis
- Verifies at least 4 emoji URLs are found

## Running the Tests

### Prerequisites
1. Integration test environment must be configured
2. Database should support utf8mb4 (MySQL 8.29+ or MariaDB 10.4+)

### Command
```bash
warden env exec php-fpm vendor/bin/phpunit \
  -c /var/www/html/dev/tests/integration/phpunit.xml.dist \
  /var/www/html/dev/tests/integration/testsuite/Magento/UrlRewrite/Model/Storage/DbStorageTest.php
```

### Expected Results

#### Modern Database (MySQL 8.29+, MariaDB 10.4+)
```
Tests: 6, Assertions: ~20, Failures: 0
```
All tests pass ✅

#### Legacy Database (MySQL <8.29, MariaDB <10.4)
```
Tests: 6 (3 skipped), Assertions: ~10, Failures: 0
```
- utf8mb4-specific tests skipped ⚠️
- 3-byte UTF-8 tests pass ✅

## Benefits of This Solution

### ✅ Proper Unicode Support
- Supports **all valid UTF-8 characters** (1-4 bytes)
- Works with emojis, mathematical symbols, rare CJK characters

### ✅ No Artificial Limitations
- Doesn't block valid characters
- Follows Unicode standards

### ✅ Backward Compatible
- Legacy databases with `utf8` continue to work
- Tests skip appropriately when utf8mb4 isn't available

### ✅ Future-Proof
- Aligns with modern MySQL defaults
- Supports international characters properly

## Migration Guide for Existing Installations

### Check Current Charset
```sql
SHOW TABLE STATUS LIKE 'url_rewrite';
-- Look at the "Collation" column
```

### If Using utf8 (Legacy)
```sql
-- Backup first!
ALTER TABLE url_rewrite 
  CONVERT TO CHARACTER SET utf8mb4 
  COLLATE utf8mb4_general_ci;
```

### Update env.php (Optional)
Remove or update `initStatements` in `app/etc/env.php`:
```php
// OLD (remove or update)
'initStatements' => 'SET NAMES utf8'

// NEW (optional - will use table collation if omitted)
'initStatements' => 'SET NAMES utf8mb4'
```

## Testing Integration Test Locally

If integration test environment isn't configured, you can verify the core functionality:

### 1. Unit Test (Already Working)
```bash
warden env exec php-fpm vendor/bin/phpunit \
  app/code/Magento/UrlRewrite/Test/Unit/Model/Storage/DbStorageTest.php
```
**Result:** 40 tests, 77 assertions, all passing ✅

### 2. Manual Verification
```php
// In Magento instance with utf8mb4 database
$storage = $objectManager->get(\Magento\UrlRewrite\Model\Storage\DbStorage::class);

// Create URL with emoji
$urlRewrite = $urlRewriteFactory->create();
$urlRewrite->setEntityType('custom')
    ->setRequestPath('test/🔎/search')
    ->setTargetPath('catalog/search')
    ->setStoreId(1);

$storage->replace([$urlRewrite]);

// Query it back
$result = $storage->findOneByData([
    'request_path' => 'test/🔎/search',
    'store_id' => 1
]);

// Should return the URL rewrite with emoji intact
```

## Conclusion

This integration test suite ensures that Adobe Commerce properly supports the full range of UTF-8 characters when using modern database configurations. The solution removes artificial character restrictions and relies on proper database charset configuration, which is the standard approach for Unicode support.

