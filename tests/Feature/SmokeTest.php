<?php

it('boots the application', function () {
    $this->get('/up')->assertOk();
});
