<table align="center" style="border-collapse:collapse !important; border:none !important;">
  <tr style="border:0px none; border-top: 0px none !important;">
    <td align="center" valign="middle" style="padding:0 1rem; border:none !important;">
      <a href="https://ibexa.co" target="_blank">
        <img src="https://vardumper.github.io/extended-htmldocument/logo-ibexa.svg" style="display:block; height:75px; width:auto; max-width:300px;" alt="Ibexa Logo" />
      </a>
    </td>
  </tr>
</table>
<h1 align="center">IbexaFormBuilderBundle</h1>

<p align="center" dir="auto">
    <a href="https://packagist.org/packages/vardumper/ibexa-form-builder-bundle" rel="nofollow">
        <img src="https://poser.pugx.org/vardumper/ibexa-form-builder-bundle/v/stable" alt="Latest Stable Version" />
    </a>
    <img src="https://img.shields.io/packagist/dt/vardumper/ibexa-form-builder-bundle" alt="Total Downloads" />
    <img src="https://img.shields.io/badge/license-mit-red" alt="License" />
    <img src="https://img.shields.io/badge/unit%20tests-passing-green?style=flat&amp;color=%234c1" style="max-width: 100%;">
    <img src="https://raw.githubusercontent.com/vardumper/IbexaFormBuilderBundle/c9c45cf556c428c69dac6bb528f2cdc6cefac1fc/coverage.svg">
    <img src="https://dtrack.erikpoehler.us/api/v1/badge/vulns/project/35387247-fc1e-4677-8c89-7bfa4c753cbb?apiKey=odt_nG83W_EAcQZkk6b5KqknIVoK8nfNjSz38Ompnn" >
</p>

Standalone Ibexa DXP bundle for rendering frontend-facing forms managed as Ibexa content. Forms are modelled as a content type tree — each field (input, textarea, select, choice, fieldset, horizontal group, button) is a separate content item nested under a form content item. Submissions are stored in a dedicated database table and/or delivered via email.

## Requirements

* PHP >= 8.3
* Ibexa DXP >= v4.4 or >= v5.0
* Symfony 5.4.x or 7.x
* Doctrine ORM ^2.11 or ^3.0

## Features

* Forms are managed as content inside the Ibexa content tree
* Uses Symfony Forms under the hood, including Validation  
* Supports horizontal grouping (eg: first name, last name)
* Tag-aware result caching via Symfony Cache (cache invalidated on content publish)
* Form submissions can be stored in a dedicated `form_submission` database table
* Form submissions can trigger an Email notifications (configurable To/CC/BCC/Subject)
* Admin UI for browsing and viewing submissions
* Console commands to install required content types
* Symfony Flex recipe for zero-configuration installation

## Installation

### 1. Install the bundle

If your project uses [Symfony Flex](https://symfony.com/doc/current/setup/flex.html) (recommended), add the private recipe endpoint to your project's `composer.json` once:

```json
"extra": {
    "symfony": {
        "endpoint": [
            "https://raw.githubusercontent.com/vardumper/IbexaFormBuilderBundle/main/flex/",
            "flex://defaults"
        ]
    }
}
```

Then install:

```bash
composer require vardumper/ibexa-form-builder-bundle
```

Symfony Flex will automatically:
- Register the bundle in `config/bundles.php`
- Copy the Doctrine ORM mapping config to `config/packages/ibexa_form_builder.yaml`
- Copy the Ibexa admin-ui config to `config/packages/ibexa_form_builder_admin_ui.yaml`
- Copy the route import to `config/routes/ibexa_form_builder.yaml`
- Copy the database migration to `migrations/`

### 2. Run the migration

The Flex recipe copies a migration to your `migrations/` directory that creates the `form_submission` table.
Run it:

```bash
bin/console doctrine:migrations:migrate
```

### 3. Install the content types

```bash
bin/console ibexa:form-builder:install-content-types
```

---

<details>
<summary>Manual installation (without Symfony Flex)</summary>

### Register the bundle in `config/bundles.php`

```php
return [
    // ...
    vardumper\IbexaFormBuilderBundle\IbexaFormBuilderBundle::class => ['all' => true],
];
```

### Register the Doctrine ORM mapping

```yaml
# config/packages/ibexa_form_builder.yaml
doctrine:
    orm:
        mappings:
            IbexaFormBuilder:
                is_bundle: false
                type: attribute
                dir: '%kernel.project_dir%/vendor/vardumper/ibexa-form-builder-bundle/src/Entity'
                prefix: 'vardumper\IbexaFormBuilderBundle\Entity'
                alias: IbexaFormBuilder
```

### Register the routes

```yaml
# config/routes/ibexa_form_builder.yaml
ibexa_form_builder_routes:
    resource: '@IbexaFormBuilderBundle/config/routes.yaml'
```

### Run the migration

Create the `form_submission` table manually or copy the migration from the bundle and run it:

```sql
CREATE TABLE form_submission (
    id          INT AUTO_INCREMENT NOT NULL,
    content_id  INT NOT NULL,
    submitted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    data        JSON NOT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
```

```bash
bin/console doctrine:migrations:migrate
```

### Install the content types

```bash
bin/console ibexa:form-builder:install-content-types
```

</details>

## Configuration

```yaml
# config/packages/ibexa_form_builder.yaml
ibexa_form_builder:
    from_email: 'no-reply@example.com'   # sender address for notification emails
```

## Console Commands

| Command | Description |
|---|---|
| `ibexa:form-builder:install-content-types` | Creates or updates the content types required by the bundle (`form`, `input`, `textarea`, `select`, `option`, `fieldset`, `horizontal_group`, `button`, `choice`) |
| `ibexa:form-builder:sync-order` | Retroactively syncs the `form_builder_order` field value → location priority so the admin sub-item list reflects the intended field order |

## Rendering a Form

Use any of the three identifiers to render a form from a controller or template:

```php
// by content ID
$this->forward('vardumper\IbexaFormBuilderBundle\Controller\FormController::renderForm', [
    'contentId' => 123,
]);

// by location ID
// by form name (value of the form_builder_name field)
```

Or link directly via the registered route:

```
/form/{identifier}
```

## Submission Handling

Set the `submission_action` field on your form content item to one of:

| Value | Behaviour |
|---|---|
| `store` | Saves the submission to the `form_submission` database table |
| `email` | Sends a notification email (requires `notification_email` field to be filled) |
| `both` | Stores the submission and sends the email |

Email fields on the form content item:

| Field | Description |
|---|---|
| `notification_email` | To address |
| `notification_email_cc` | CC address (optional) |
| `notification_email_bcc` | BCC address (optional) |
| `email_subject` | Email subject line (optional) |


## Extending the Form Theme and Templates



## Events

The bundle dispatches six events throughout the form submission lifecycle, giving you fine-grained control without needing to override any services. All event names are defined as constants on `FormBuilderEvents`.

| Constant | Dispatched | Cancellable |
|---|---|---|
| `PRE_VALIDATION` | After `handleRequest()`, before `isValid()` is evaluated | ✔ Cancelling prevents `SubmissionHandler` from running at all |
| `PRE_SUBMIT` | After POST data is cleaned, before any storage or email action | ✔ Cancelling skips both; listeners may also call `setData()` to enrich or sanitize the data |
| `PRE_STORE_SUBMISSION` | Before `persist()` + `flush()` | ✔ Cancelling skips the DB write; the email action still proceeds |
| `POST_STORE_SUBMISSION` | After `flush()`; entity carries its auto-generated ID | ✗ |
| `PRE_SEND_EMAIL` | After the `Email` object is built, before sending | ✔ Cancelling suppresses the send; mutate the `Email` object directly to change recipients, subject, or body |
| `POST_SUBMIT` | End of `handle()`, regardless of cancellations | ✗ Always fires; `getSubmission()` returns `null` when the store step was skipped |

Cancellable events expose `cancel()` and `isCancelled()`. Calling `cancel()` does **not** call `stopPropagation()`, so subsequent listeners on the same event still receive it.

### Example: reject submissions that appear to be spam

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PreSubmitEvent};

#[AsEventListener(event: FormBuilderEvents::PRE_SUBMIT)]
final class SpamFilterListener
{
    public function __invoke(PreSubmitEvent $event): void
    {
        $data = $event->getData();

        if (isset($data['website']) && $data['website'] !== '') {
            $event->cancel(); // honeypot field was filled — silently discard
            return;
        }

        // Strip HTML from all string values before storage
        $event->setData(array_map(
            static fn (mixed $v) => is_string($v) ? strip_tags($v) : $v,
            $data,
        ));
    }
}
```

### Example: push every stored submission to an external CRM

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use GuzzleHttp\ClientInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostStoreSubmissionEvent};

#[AsEventListener(event: FormBuilderEvents::POST_STORE_SUBMISSION)]
final class CrmIntegrationListener
{
    public function __construct(private readonly ClientInterface $httpClient)
    {
    }

    public function __invoke(PostStoreSubmissionEvent $event): void
    {
        $submission = $event->getSubmission();

        $this->httpClient->request('POST', 'https://crm.example.com/api/leads', [
            'json' => [
                'source_id' => $submission->getId(),
                'form_id'   => $submission->getContentId(),
                'data'      => $submission->getData(),
            ],
        ]);
    }
}
```

## Run Tests

This bundle uses [Pest](https://pestphp.com/) for testing.

```bash
composer install
vendor/bin/pest
```

### Coverage Report

```bash
XDEBUG_MODE=coverage vendor/bin/pest --coverage-html=coverage-report
```
