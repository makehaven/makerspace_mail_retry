<?php

declare(strict_types=1);

namespace Drupal\makerspace_mail_retry_test\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;

/**
 * A mail plugin that always reports failure.
 *
 * Stands in for the real symptom: the SMTP plugin catching a connection
 * error, logging it and returning FALSE.
 *
 * @Mail(
 *   id = "makerspace_mail_retry_failing",
 *   label = @Translation("Always fails"),
 *   description = @Translation("Never sends anything. Test use only.")
 * )
 */
final class FailingMailSystem implements MailInterface {

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    if (is_array($message['body'])) {
      $message['body'] = implode("\n\n", $message['body']);
    }
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    return FALSE;
  }

}
