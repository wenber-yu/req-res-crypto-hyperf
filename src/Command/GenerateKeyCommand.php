<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Wenbo\ReqResCrypto\Core\KeyPair;

#[Command]
final class GenerateKeyCommand extends HyperfCommand
{
    protected ?string $signature = 'req-res-crypto:keys:generate';

    protected string $description = 'Generate key pairs and print .env snippet for static mode';

    public function handle(): int
    {
        $signKp = KeyPair::generate();
        $exchangeKp = KeyPair::generate();

        $output = $this->getOutput();

        $output->writeln('');
        $output->writeln('<comment># Copy the following into your .env file:</comment>');
        $output->writeln('');
        $output->writeln('REQ_RES_CRYPTO_KEY_ID='.$signKp->keyId());
        $output->writeln('REQ_RES_CRYPTO_SIGN_SECRET_KEY='.bin2hex($signKp->signSecretKey));
        $output->writeln('REQ_RES_CRYPTO_SIGN_PUBLIC_KEY='.bin2hex($signKp->signPublicKey));
        $output->writeln('REQ_RES_CRYPTO_EXCHANGE_SECRET_KEY='.bin2hex($exchangeKp->exchangeSecretKey));
        $output->writeln('REQ_RES_CRYPTO_EXCHANGE_PUBLIC_KEY='.bin2hex($exchangeKp->exchangePublicKey));
        $output->writeln('');
        $output->writeln('<info>Done. Keys generated — paste the snippet above into your .env file.</info>');

        return self::SUCCESS;
    }
}
