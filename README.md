# Introduction

This is a music share application named isesame_music using the Hyperf framework. This application is meant to be used as a share your favorite musice to other guys.

# Requirements

isesame_music has some requirements for the system environment, it can only run under Linux and Mac environment, but due to the development of Docker virtualization technology, Docker for Windows can also be used as the running environment under Windows.

directly based on the already built [isesame-Ch/isesame_music](https://github.com/isesame-Ch/isesame_music) Image to run.

When you don't want to use Docker as the basis for your running environment, you need to make sure that your operating environment meets the following requirements:  

 - PHP >= 7.2
 - Swoole PHP extension >= 4.4，and Disabled `Short Name`
 - OpenSSL PHP extension
 - JSON PHP extension
 - PDO PHP extension （If you need to use MySQL Client）
 - Redis PHP extension （If you need to use Redis Client）
 - Protobuf PHP extension （If you need to use gRPC Server of Client）
 - PYTHON >= 3
- MUTAGEN extension

# Installation using Composer

The easiest way to create a new Hyperf project is to use Composer. If you don't have it already installed, then please install as per the documentation.

To create your new Hyperf project:

$ composer create-project hyperf/hyperf-skeleton path/to/install

Once installed, you can run the server immediately using the command below.

```
$ cd path/to/install
$ php bin/hyperf.php start
```

This will start the cli-server on port `9501` and port `9502`, and bind it to all network interfaces. You can then visit the site at `http://localhost:9501/` which will bring up isesame_music default home page.

You can open index.html and links `ws://localhost:9502` to start listen your music.

