<?php

declare(strict_types=1);

namespace Drupal\makerspace_mail_retry\Plugin\Mail;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends via another mail plugin and retries what that plugin could not send.
 *
 * @Mail(
 *   id = "makerspace_mail_retry",
 *   label = @Translation("Retrying mail system"),
 *   description = @Translation("Delegates to the configured mail plugin and queues anything it fails to send for a later attempt.")
 * )
 */
final class RetryingMailSystem implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * Queue name shared with the retry worker.
   */
  public const QUEUE = 'makerspace_mail_retry';

  /**
   * Params the delegate reads. Everything else is dropped before queueing.
   *
   * A $message passed to mail() has already been through format(), so the
   * body is a finished string and params are no longer needed to build it.
   * Callers routinely park whole entities in params (an account, a node),
   * and putting those in a queue means storing a snapshot that is stale by
   * the time it is read. Keeping only what the delegate actually reads makes
   * the queued payload small and safe to serialize.
   */
  private const RETAINED_PARAMS = ['from_name', 'from_mail', 'attachments'];

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('config.factory'),
      $container->get('queue'),
      $container->get('datetime.time'),
      $container->get('logger.factory')->get('makerspace_mail_retry'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    return $this->delegate()->format($message);
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    if ($this->delegate()->mail($message)) {
      return TRUE;
    }

    // The delegate has already logged why it failed. Decide whether this
    // message gets another chance.
    $max = (int) $this->settings()->get('max_retries');
    if ($max < 1) {
      return FALSE;
    }

    return $this->enqueue($message, 0);
  }

  /**
   * Queues a message for a later attempt.
   *
   * @param array $message
   *   The formatted message, as handed to mail().
   * @param int $attempts
   *   How many retries have already been spent on it.
   *
   * @return bool
   *   TRUE if the message is now queued. A queued message is reported to the
   *   caller as accepted: delivery is deferred, not abandoned, and telling a
   *   caller the send failed would have them show an error for a message that
   *   is about to arrive.
   */
  public function enqueue(array $message, int $attempts): bool {
    $delay = $this->backoffFor($attempts);

    try {
      $this->queueFactory->get(self::QUEUE)->createItem([
        'message' => $this->strip($message),
        'attempts' => $attempts,
        'not_before' => $this->time->getRequestTime() + $delay,
      ]);
    }
    catch (\Throwable $e) {
      // Nothing else can save the message at this point, so be loud and let
      // the caller see the failure.
      $this->logger->error('Could not queue a failed message to @to (subject: @subject) for retry: @message', [
        '@to' => $message['to'] ?? '(unknown)',
        '@subject' => $message['subject'] ?? '(none)',
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $this->logger->warning('Send to @to failed (subject: @subject); queued for retry @n in @delay seconds.', [
      '@to' => $message['to'] ?? '(unknown)',
      '@subject' => $message['subject'] ?? '(none)',
      '@n' => $attempts + 1,
      '@delay' => $delay,
    ]);

    return TRUE;
  }

  /**
   * The plugin that performs the actual send.
   */
  public function delegate(): MailInterface {
    $id = (string) $this->settings()->get('inner_plugin');
    // createInstance() with an explicit id does not consult system.mail, so
    // this cannot resolve back to this plugin and recurse.
    return $this->mailManager->createInstance($id ?: 'php_mail');
  }

  /**
   * Seconds to wait before the retry that follows $attempts spent retries.
   */
  public function backoffFor(int $attempts): int {
    $backoff = $this->settings()->get('backoff');
    if (!is_array($backoff) || $backoff === []) {
      return 300;
    }
    // Short lists repeat their last value rather than falling off the end.
    $index = min($attempts, count($backoff) - 1);
    return max(0, (int) $backoff[$index]);
  }

  /**
   * Reduces a message to what can be safely stored and later re-sent.
   */
  private function strip(array $message): array {
    $params = [];
    foreach (self::RETAINED_PARAMS as $key) {
      if (isset($message['params'][$key])) {
        $params[$key] = $message['params'][$key];
      }
    }
    $message['params'] = $params;

    return $message;
  }

  /**
   * This module's settings.
   */
  private function settings() {
    return $this->configFactory->get('makerspace_mail_retry.settings');
  }

}
