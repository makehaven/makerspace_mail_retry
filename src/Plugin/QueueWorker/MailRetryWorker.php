<?php

declare(strict_types=1);

namespace Drupal\makerspace_mail_retry\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\makerspace_mail_retry\Plugin\Mail\RetryingMailSystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-attempts messages the mail plugin failed to send.
 *
 * @QueueWorker(
 *   id = "makerspace_mail_retry",
 *   title = @Translation("Retry failed outgoing mail"),
 *   cron = {"time" = 60}
 * )
 */
final class MailRetryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
    private readonly int $now,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.mail'),
      $container->get('config.factory'),
      $container->get('queue'),
      $container->get('logger.factory')->get('makerspace_mail_retry'),
      (int) $container->get('datetime.time')->getRequestTime(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !isset($data['message']['to'])) {
      $this->logger->error('Discarded an unreadable mail retry item.');
      return;
    }

    $message = $data['message'];
    $attempts = (int) ($data['attempts'] ?? 0);
    $to = $message['to'];
    $subject = $message['subject'] ?? '(none)';

    // Core's database queue has no scheduled availability, so the wait is
    // carried on the item. Anything not yet due goes straight back on the
    // queue; returning normally deletes the copy we just claimed.
    if ($this->now < (int) ($data['not_before'] ?? 0)) {
      $this->requeue($data);
      return;
    }

    try {
      /** @var \Drupal\makerspace_mail_retry\Plugin\Mail\RetryingMailSystem $plugin */
      $plugin = $this->mailManager->createInstance('makerspace_mail_retry');
    }
    catch (\Throwable $e) {
      // The module is being uninstalled or the container is mid-rebuild. Put
      // the message back rather than throwing away something still sendable.
      $this->logger->warning('Could not load the retry plugin; leaving @to (subject: @subject) queued: @error', [
        '@to' => $to,
        '@subject' => $subject,
        '@error' => $e->getMessage(),
      ]);
      $this->requeue($data);
      return;
    }

    if ($plugin->delegate()->mail($message)) {
      $this->logger->info('Retry @n delivered the message to @to (subject: @subject).', [
        '@n' => $attempts + 1,
        '@to' => $to,
        '@subject' => $subject,
      ]);
      return;
    }

    $attempts++;
    $max = (int) $this->configFactory->get('makerspace_mail_retry.settings')->get('max_retries');

    if ($attempts >= $max) {
      // Say exactly what was lost. This is the only record a person gets, and
      // "an email failed" is not something anyone can act on.
      $this->logger->error('Giving up on a message to @to (subject: @subject) after @n retries. It was never delivered.', [
        '@to' => $to,
        '@subject' => $subject,
        '@n' => $attempts,
      ]);
      return;
    }

    $plugin->enqueue($message, $attempts);
  }

  /**
   * Puts an item back without counting it as an attempt.
   */
  private function requeue(array $data): void {
    $this->queueFactory->get(RetryingMailSystem::QUEUE)->createItem($data);
  }

}
