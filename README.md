osTicket-Chinese
========

[osTicket](https://osticket.com) 是一个开源、优秀的票务管理系统。

但是在中文环境的使用上，有一些不足。

这里基于[osTicket 1.10 Release](https://github.com/osTicket/osTicket/releases/download/v1.10/osTicket-v1.10.zip)，针对中文使用环境进行了一些调整。

# 中文语言包

官方下载的中文语言包（2017年1月13日），与1.10不兼容。

通过对比1.10发布包中自带的en_US包，发现主要是因为1.10中调整了form的格式，将一些bool类型的标记调整为bit标识位。

[官方中文语言包](https://github.com/liangxinhui/osTicket-Chinese/blob/master/osTicket-1.10_zh_CH/zh_CN.phar)

[修正后的中文语言包](https://github.com/liangxinhui/osTicket-Chinese/blob/master/osTicket-1.10_zh_CH/zh_CN_v1.10_fixed.zip)


# 获取数据库时区的代码

osTicket中关于默认时区有一些问题，针对系统默认配置的时区和个人配置中的时区不太管用。

通过调试代码发现：

- 每次登陆一个Session后，都会认为当前用户是没有时区配置的（即便你在系统中有配置），然后会取默认时区。
- 这个默认时区，是取的数据库系统时区(system_time_zone)。
- 在mysql中，system_time_zone，+8:00时区显示为CST，而osTicket中，会将CST翻译为American/Chicago时间。
- 这样，最终的时间就会出现问题。这里调整了system_time_zone为time_zone。

mysql数据库配置需要设定：
```
[msyqld]
default-time_zone='+8:00'
```

修改明细：

https://github.com/liangxinhui/osTicket-Chinese/commit/4ce5f4e4c35cef1722b0f7e12ed6133fbc4fd145


# 中文搜索
默认的搜索使用的是mysql的Full-Text Search。这种搜索方式效率较高，但是对中文的体验很不友好：

只能搜索一句话的开头部分，比如“这是一句中文”，只能以“这是一句”搜索，如果搜索“一句中文”，是命中不了的。

这里暂时修改为like的模式，对于数据量小的时候，凑合着用吧。如果哪位高手看到了，希望能优化下unicode文字的搜索算法。

修改明细：

https://github.com/liangxinhui/osTicket-Chinese/commit/38378cf8510aa10d9a22f9251bd87007849d71f5

TODO: 将 [结巴中文分词](https://github.com/fxsjy/jieba) 应用过来。
