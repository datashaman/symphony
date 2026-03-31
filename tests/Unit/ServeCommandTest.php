<?php

use App\Commands\ServeCommand;

it('has correct command signature', function () {
    $command = new ServeCommand;

    expect($command->getName())->toBe('serve');
    expect($command->getDescription())->toBe('Start the Symphony web UI server');
});
