# Testing Guide

**By the end of this guide, you'll be able to:** Test order types, use test helpers, create fakes, and write comprehensive test cases.

---

## Testing Order Types

### Basic Structure Test

```php
use Tests\TestCase;
use App\WorkTypes\UserDataSyncType;

class UserDataSyncTypeTest extends TestCase
{
    public function test_type_identifier()
    {
        $type = new UserDataSyncType();

        $this->assertEquals('user.data.sync', $type->type());
    }

    public function test_schema_structure()
    {
        $type = new UserDataSyncType();
        $schema = $type->schema();

        $this->assertArrayHasKey('required', $schema);
        $this->assertContains('user_ids', $schema['required']);
    }
}
```

### Testing Validation

```php
use GregPriday\WorkManager\Models\WorkItem;
use Illuminate\Validation\ValidationException;

public function test_validates_submission()
{
    $type = new UserDataSyncType();
    $item = WorkItem::factory()->create();

    $this->expectException(ValidationException::class);

    $type->acceptancePolicy()->validateSubmission($item, [
        'invalid' => 'data',
    ]);
}
```

### Testing Idempotency

```php
use GregPriday\WorkManager\Models\WorkOrder;

public function test_apply_is_idempotent()
{
    $order = WorkOrder::factory()->create([
        'type' => 'user.data.sync',
        'payload' => ['user_ids' => [1, 2, 3]],
    ]);

    $type = new UserDataSyncType();

    $diff1 = $type->apply($order);
    $diff2 = $type->apply($order);

    $this->assertEquals($diff1->toArray(), $diff2->toArray());
}
```

---

## Running Tests

```bash
composer test

# With coverage
vendor/bin/pest --coverage

# Specific test
vendor/bin/pest tests/Feature/UserDataSyncTypeTest.php
```

---

## See Also

- [Creating Order Types Guide](creating-order-types.md)
- [Validation Guide](validation-and-acceptance-policies.md)
- [examples/](../../examples/) - Example implementations
