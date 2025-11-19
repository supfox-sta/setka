# <img align="right" src="/Web/static/img/logo_shadow.png" alt="Сетка" title="Сетка" width="15%">Сетка | Social Network of Nostalgia

_[Русский](README_RU.md)_

**Сетка** is a free and open-source social network platform inspired by the classic VKontakte (VK) interface and features. It's designed for nostalgia lovers who miss the old-school social networking experience.

This project is based on [OpenVK](https://github.com/openvk/openvk), a fan-made initiative not affiliated with VKontakte or its parent company VK Ltd. It's built using PHP and modern web technologies to recreate the familiar feel of early 2000s social media.

**Note for GitHub users**: This repository has been sanitized for public sharing. Configuration files (`openvk.yml`) have had sensitive information (API keys, passwords, personal data) replaced with placeholders. You will need to configure these before deploying.

## Installation

For installation instructions, please visit: [https://vsetke.fun/install](https://vsetke.fun/install)

## Features

### Core Social Features
- **User Profiles**: Create and customize personal profiles with photos, status updates, and personal information
- **Posts & Walls**: Share text posts, photos, and links on your wall or friends' walls
- **Friends System**: Connect with other users, manage friend requests and relationships
- **Groups & Communities**: Create and join interest-based groups, participate in discussions
- **Private Messaging**: Send private messages to other users with real-time chat

### Media & Content
- **Photo Albums**: Upload and organize photos in albums with descriptions
- **Video Sharing**: Upload and share videos with the community
- **Music Library**: Listen to and share music tracks
- **Notes**: Write and publish longer-form content as notes
- **Polls**: Create polls to engage with your audience

### Advanced Features
- **News Feed**: Stay updated with posts from friends and groups
- **Notifications**: Get notified about important activities
- **Search**: Find users, groups, and content across the network
- **API Support**: Integrate with external applications via REST API
- **Themes & Customization**: Choose from various themes and customize your experience
- **Multi-language Support**: Available in multiple languages including Russian, English, and more

### Administration & Moderation
- **Admin Panel**: Comprehensive administration tools for managing the network
- **User Moderation**: Tools for moderating content and users
- **Support System**: Built-in ticketing system for user support
- **Rate Limiting**: Prevent spam and abuse with configurable rate limits
- **Backup & Recovery**: Database backup and recovery tools

### Technical Features
- **Scalable Architecture**: Built on Chandler Application Server for high performance
- **Database Support**: MySQL/MariaDB with optional ClickHouse for analytics
- **Security**: Built-in security features including CSRF protection, input sanitization
- **Mobile Responsive**: Works great on desktop and mobile devices
- **Docker Support**: Easy deployment with Docker containers
- **Kubernetes Ready**: Production-ready deployment configurations

To be honest, we don't know whether if it even works. However, this version is maintained and we will be happy to accept your bugreports [in our bug tracker](https://github.com/openvk/openvk/projects/1). You should also be able to submit them using [ticketing system](https://ovk.to/support?act=new) (you will need an OpenVK account for this).

## When's the release?

We will release Сетка as soon as it's ready. As for now, you can:
* `git clone` this repo's master branch (use `git pull` to update)
* Grab a prebuilt Сетка distro from [GitHub artifacts](https://nightly.link/openvk/archive/workflows/nightly/master/OpenVK%20Archive.zip)

## Instances

A list of instances can be found in [our wiki of this repository](https://github.com/openvk/openvk/wiki/Instances).

## Can I create my own Сетка instance?

Yes! And you are very welcome to.

However, Сетка makes use of Chandler Application Server. This software requires extensions, that may not be provided by your hosting provider (namely, sodium and yaml. these extensions are available on most of ISPManager hostings).

If you want, you can add your instance to the list above so that people can register there.

### If my website uses OpenVK, should I release its sources?

It depends. You can keep the sources to yourself if you do not plan to distribute your website binaries. If your website software must be distributed, it can stay non-OSS provided the OpenVK is not used as a primary application and is not modified. If you modified OpenVK for your needs or your work is based on it and you are planning to redistribute this, then you should license it under terms of any LGPL-compatible license (like OSL, GPL, LGPL etc).

## Where can I get assistance?

You may reach out to us via:

* [Bug Tracker](https://github.com/openvk/openvk/projects/1)
* [Ticketing System](https://ovk.to/support?act=new)
* Telegram Chat: Go to [our channel](https://t.me/openvkenglish) and open discussion in our channel menu.
* [Reddit](https://www.reddit.com/r/openvk/)
* [GitHub Discussions](https://github.com/openvk/openvk/discussions)
* Matrix Chat: #openvk:matrix.org

**Attention**: bug tracker, board, Telegram and Matrix chat are public places, ticketing system is being served by volunteers. If you need to report something that should not be immediately disclosed to general public (for instance, a vulnerability), please contact us directly via this email: **contact [at] ovk [dot] to**

<a href="https://codeberg.org/OpenVK/openvk">
    <img alt="Get it on Codeberg" src="https://codeberg.org/Codeberg/GetItOnCodeberg/media/branch/main/get-it-on-blue-on-white.png" height="60">
</a>
