# bloog v0.1

bloog is a lightweight single file web application that allows anyone to use markdown files to generate a personal blog. Features include caching using APC and configuration overrides with a different file.

## License
Copyright (c) 2013 Duane Jeffers

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

## Installation
Installation is pretty simple.

### 1) Clone
First you need to clone to the directory you want to use, using the following command:  
`git clone https://github.com/duanejeffers/bloog.git`

### 2) Composer Install
This application uses composer to download the Markdown dependency. To install composer, go to http://getcomposer.org, then run the following command:  
`php composer.phar install`

### 3) (Optional) Clean URL support
Ideally, you don't need to have clean URL support to use this script. However, if you feel the need to use clean URL support, the following apache configuration is ideal:  
```ApacheConf
RewriteEngine On  
RewriteCond %{REQUEST_FILENAME} -s [OR]  
RewriteCond %{REQUEST_FILENAME} -l [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^.*$ - [NC,L]
RewriteRule ^.*$ bloog.php [NC,L]
```

## Using bloog
Your markdown content files need to have the configuration options at the beginning of the file, and separated with a new line `---`. Once a `published` option is set to `true`, then the content will be live on the site.

### Example
```Markdown
# published: true
# title: Content Title
# author: [content author](/about)
# publishdate: September 25, 2012 12:02:23
# listed: true
---
Content here
[teaser_break]
More content here.
```
### Required Content Config Options
- Config options that are listed as a bool MUST either be `true` or `false`. Anything else will trigger an error.
- Time strings are converted using strtotime(), meaning it must be something that is parsed by the function. To read more about it, visit: http://php.net/strtotime
- ALL strings are parsed through the Markdown-to-HTML renderer. If you don't want to have the string to be encapsulated in an html tag, you may need to create a function in your .bloogconfig.php file to strip the tags.

#### published - bool
This puts the content live on the site. This does not mean it will be included in the blog list view, but it will be accessible via the url. If you specify a publishdate, however, this is not required, because the publishdate will let bloog display the content after the publishdate.

#### publishdate - time string
This allows you to specify the publishdate. If you want a specific time to publish, then use this. This config option will let you publish the content without needing the published config option. (NOTE: If you have published with a publishdate in the future, bloog will still display the content)

#### listed - bool
This will put the content, using the teaser functionality, in the blog list view, when accessing a folder.

#### title - string
This is the content title.

#### author - string
This is the author of the content.

### Optional Content Config Options.
You can specify optional config options in your markdown file, these are options that will bloog is already setup to handle, but do not have any display output (You will need to put the output in your .bloogconfig.php file).

#### comments - bool
This will let you specify if the content is supposed to have comments attached to the file. By default, this is set to true in the content object.

#### breadcrumbs - bool
This will let you specify if the content is supposed to use breadcrumbs. Use this with the view helper parseBreadcrumbs() and it will spit out a unordered list with each directory having it's own link. By default this is set to true in the content object.

### Optional system config file.
bloog has the capability to load in an optional config file in the same directory as the bloog script. Every option is overrideable and will allow you to change everything from the layout to the location of the content bloog will pull. The optional config file is called `.bloogconfig.php` and will be loaded in before any rendering takes place.