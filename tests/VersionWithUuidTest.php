<?php

namespace Tests;

class VersionWithUuidTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('versionable.uuid', true);
    }

    public function setUp(): void
    {
        parent::setUp();

        Post::enableVersioning();

        config([
            'auth.providers.users.model' => User::class,
            'versionable.user_model' => User::class,
        ]);
    }

    public function testUuid()
    {
        $user = User::create(['name' => 'overtrue']);
        $this->actingAs($user);

        $post = Post::create(['title' => 'Hello world!', 'content' => 'Hello world!']);
        $version = $post->versions()->first();

        $this->assertIsString($version->id);
    }

    public function testUuidGetVersion()
    {
        $user = User::create(['name' => 'overtrue']);
        $this->actingAs($user);

        $post = Post::create(['title' => 'Hello world!', 'content' => 'Hello world!']);
        $original_version = $post->versions()->first();

        //Confirms we are using UUID
        $this->assertIsString($original_version->id);

        //Breaks in v5.3.2 and earlier.
        $version = $post->getVersion($original_version->id);

        $this->assertEquals($original_version->id, $version->id);
    }

    public function testUuidRestoreToVersion()
    {
        $user = User::create(['name' => 'overtrue']);
        $this->actingAs($user);

        $post = Post::create(['title' => 'Hello world!', 'content' => 'Hello world!']);
        $original_version = $post->versions()->first();

        //Confirms we are using UUID
        $this->assertIsString($original_version->id);

        //Update the Title to get a new version
        $post->update(['title' => 'A New World!']);

        $this->assertCount(2, $post->refresh()->versions);

        $new_version = $post->latestVersion;
        $this->assertNotEquals($new_version->id, $original_version->id);

        //Breaks with v5.3.2
        $post->revertToVersion($original_version->id);

        $this->assertCount(3, $post->refresh()->versions);
        $this->assertEquals('Hello world!', $post->title);
    }

    public function testUuidRemoveVersion()
    {
        $user = User::create(['name' => 'overtrue']);
        $this->actingAs($user);

        $post = Post::create(['title' => 'Hello world!', 'content' => 'Hello world!']);
        $original_version = $post->versions()->first();

        //Confirms we are using UUID
        $this->assertIsString($original_version->id);

        //Update the Title to get a new version
        $post->update(['title' => 'A New World!']);

        $this->assertCount(2, $post->refresh()->versions);

        //Breaks in v5.3.2 and earlier.
        $post->removeVersion($original_version->id);

        $this->assertCount(1, $post->refresh()->versions);

    }

    public function testUuidForceRemoveVersion()
    {
        $user = User::create(['name' => 'overtrue']);
        $this->actingAs($user);

        $post = Post::create(['title' => 'Hello world!', 'content' => 'Hello world!']);
        $original_version = $post->versions()->first();

        //Confirms we are using UUID
        $this->assertIsString($original_version->id);

        //Update the Title to get a new version
        $post->update(['title' => 'A New World!']);

        $this->assertCount(2, $post->refresh()->versions);

        //Breaks in v5.3.2 and earlier.
        $post->forceRemoveVersion($original_version->id);

        $this->assertCount(1, $post->refresh()->versions);
    }

    public function testUuidRestoreTrashedVersion()
    {
        $user = User::create(['name' => 'overtrue']);
        $this->actingAs($user);

        $post = Post::create(['title' => 'Hello world!', 'content' => 'Hello world!']);
        $original_version = $post->versions()->first();

        //Confirms we are using UUID
        $this->assertIsString($original_version->id);

        //Update the Title to get a new version
        $post->update(['title' => 'A New World!']);

        $this->assertCount(2, $post->refresh()->versions);

        //Breaks in v5.3.2 and earlier.
        $post->removeVersion($original_version->id);

        $this->assertCount(1, $post->refresh()->versions);

        //Breaks in v5.3.2 and earlier.
        $post->restoreTrashedVersion($original_version->id);

        $this->assertCount(2, $post->refresh()->versions);
    }
}
