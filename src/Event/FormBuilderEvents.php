<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

final class FormBuilderEvents
{
    public const PRE_VALIDATION = 'ibexa_form_builder.pre_validation'; /** @see PreValidationEvent — cancel to abort the submission pipeline */

    public const PRE_SUBMIT = 'ibexa_form_builder.pre_submit'; /** @see PreSubmitEvent — cancel to skip storage + email; mutate data via setData() */

    public const PRE_STORE_SUBMISSION = 'ibexa_form_builder.pre_store_submission'; /** @see PreStoreSubmissionEvent — cancel to skip DB write; email still proceeds */

    public const POST_STORE_SUBMISSION = 'ibexa_form_builder.post_store_submission'; /** @see PostStoreSubmissionEvent — entity has its auto-generated ID */

    public const PRE_SEND_EMAIL = 'ibexa_form_builder.pre_send_email'; /** @see PreSendEmailEvent — cancel to suppress send; mutate Email object freely */

    public const POST_SUBMIT = 'ibexa_form_builder.post_submit'; /** @see PostSubmitEvent — always fires; use for analytics / audit logging */

    private function __construct()
    {
    }
}
