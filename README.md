# aws-s3-resize-image基于S3的图片处理服务

1.背景介绍
随着移动互联网的快速发展，各种移动终端设备爆发式的增长。社交类APP 或者电商网站为了提升访问速度、提高用户体验，必须根据客户端的不同性能，不同屏幕尺寸和分辨率提供适当尺寸的图片。这样一来开发者通常需要预先提供非常多种不同分辨率的图片组合，而这往往导致管理难度的提升和成本的增加。

在这篇博客中，我们将探讨一种基于S3的图片处理服务，客户端根据基于HTTP URL API 请求实时生成不同分辨率的图片。

2.解决方案架构

为了保证图片服务的高可用，我们会把所有的图片（包括原图和缩率图）储存于AWS 的对象储存服务S3中，把图片处理程序部署于AWS EC2 中。大部分的图片请求都会直接由AWS S3返回，只有当S3中不存在所需缩率图时才会去请求部署于EC2 上的图片处理服务来生成对应分辨率图片。

业务流程：
1.	用户客户端（通常是浏览器或者APP）发起请求200x200像素的图片
2.S3中未存在该尺寸缩率图，于是返回HTTP 302 Redirect到客户端
3.客户端根据 HTTP 302响应，继续请求部署于 EC2 上的图片处理服务
4.部署于EC2的图片处理服务，会从 S3 获取源图
5.图片处理服务，根据请求参数生成对应尺寸图片缩率图
6.图片处理服务将对应的图片返回给客户端
7.图片处理服务把缩率图存回到S3中，加速下一次客户访问

3.架构特点
相较于预先生成所有所需分辨率图片和传统的基于独立图片处理服务器，这种新的处理方式包含以下优点：

更高的灵活性：
当我们的前端开放人员对界面进行改版时，时常会涉及到修改图片尺寸。对所有原始图片进行缩放的批处理是十分耗时，高成本且易出错的。利用这种基于URL API的实时处理方式，前端开发者指定新的尺寸后，立即就能生成符合客户访问新的网页和应用对应的图片，提高了前端开发人员的开发效率。

更低的储存成本：
对于传统图片处理方式，在未使用之前预先批量生成所需分辨率图片，占用大量的储存空间 ，而且利用率并不高。
以按需方式生成图片，能减少不必要的储存空间，降低储存成本。
相对于独立图片处理服务器，把图片完全储存于EC2 的 EBS 卷相比，储存于S3 的成本也更低。
我们知道缩率图时属于可再生数据，所以我们在程序上 会把缩率图储存为 S3的去冗余储存类型，再次降低约13% 的存储成本。

更高的服务可用性：
我们可以看到，独立的部署单台图片服务器，无法支持大并发负载。同时存在单点故障 无法保证服务的高可用性，高可扩展性。
而基于S3的解决方案，大量的图片请求，由AWS S3 来完成，S3 会自动扩展性能，因此应用程序的运行速度在数据增加时不会减慢。
只有少量的未生成的图片尺寸才会用到部署于EC2 的图片服务器，并且我们图片逻辑服务器与图片储存S3是解耦合关系，我们还可以针对 基于EC2的图片处理服务 进行横向扩展，比如把图片服务程序 加入到 Web服务器层，配合 负载均衡器 ELB、自动伸缩组 AutoScaling 来实现自动伸缩。


4.服务部署
为了服务部署能更接近生产环境，我们以下所有步骤将以创建一个以域名为 images.awser.me 的图片服务为例，来详细介绍如何基于AWS 来部署。

在EC2上部署图片处理服务
1.	创建一台EC2服务器，并赋予 具有S3 访问权限的IAM 角色，配置正确的安全组开启 HTTP 80端口访问权限。

2.	示例程序基于PHP，所以需要确保服务器安装了 Apache 2.4 和PHP 7.0 以上。以及PHP 图片处理的相关的组件：ImageMagick、ImageMagick-devel 。

参考 基于Amazon Linux 的安装命令：
yum -y install httpd24 php70 ImageMagick  ImageMagick-devel php70-pecl-imagick php70-pecl-imagick-devel

3.	从 github 或者 S3 下载图片处理程序，修改 根目录 resize.php 文件，bucketName 变量为你的域名或者是你的储存桶名称，例如：$bucketName='images.awser.me'。
将程序部署于你的 web 目录底下，并且确保 能通过EC2 公网IP  进行访问。
例如：http://52.80.80.80/resize.php?src=test_200x200.jpg

最好为你的图片服务器设置一个域名，我们这里设置了 imgpro.awser.me。
例如: http://imgpro.awser.me/resize.php?src=test_200x200.jpg
 
这里记录下你的 EC2 Web 访问地址，在下一步的 S3 储存桶配置中会用到。

创建和配置S3储存桶

1.	创建储存桶，由于我们在生产环境中，需要使用指定的域名来访问S3资源。所以我们需要在S3 控制台 创建储存桶时，把储存桶名称设置为我们的域名，如： images.awser.me。
2.	在刚刚创建的S3 储存桶，权限选项卡中 选择储存桶策略填写如下内容（注意修改 images.awser.me 为你的域名 或者 储存桶名称） 开启匿名访问。
{
  "Version":"2012-10-17",
  "Statement":[
    {
      "Sid":"AddPerm",
      "Effect":"Allow",
      "Principal": "*",
      "Action":["s3:GetObject"],
      "Resource":["arn:aws-cn:s3:::images.awser.me/*"]
    }
  ]
}

3.	在刚刚创建的S3储存桶，属性选项卡中 开启 静态网站托管 ，并配置 重定向规则 为以下示例内容（请修改HostName 为上一步骤 所创建的EC2 Web访问地址）：
<RoutingRules>
  <RoutingRule>
    <Condition>
      <KeyPrefixEquals/>
      <HttpErrorCodeReturnedEquals>404</HttpErrorCodeReturnedEquals>
    </Condition>
    <Redirect>
      <Protocol>http</Protocol>
      <HostName>imgpro.awser.me</HostName>
      <ReplaceKeyPrefixWith>resize.php?src=</ReplaceKeyPrefixWith>
      <HttpRedirectCode>302</HttpRedirectCode>
    </Redirect>
  </RoutingRule>
</RoutingRules>

记录终端节点名称 例如：images.awser.me.s3-website.cn-north-1.amazonaws.com.cn

在DNS 服务商 添加一个CNAME记录 ，把你的自定义域名 如images.awser.me 解析到 该终端节点上。

5.测试图片缩放
首先上传一张测试图片至S3存储桶，我们选择一张经典的地球图片作为测试 命名为earth.jpg。
1.	测试使用 S3 终端节点访问，测试储存桶 权限是否配置正确：
http://images.awser.me.s3-website.cn-north-1.amazonaws.com.cn/earth.jpg

2.	测试使用 自定义域名访问，测试储存桶命名以及DNS 配置是否正确
http://images.awser.me/earth.jpg

3.	测试图片缩放功能是否正常，尝试请求一张 200x200 像素的图片：
http://images.awser.me/earth_200x200.jpg

如果一切正常，我们可以看到浏览器 会自动跳转访问
http://imgpro.awser.me/resize.php?src=earth_200x200.jpg
并返回200x200 像素的图片。

4.	测试S3 缓存是否生效，当我们第二次访问
http://images.awser.me/earth_200x200.jpg
如果一切正常，我们可以观察到 浏览器 没有做跳转。

6.总结
以上内容是对基于S3的图片处理服务解决方案的一个简单实现，也提到一部分生产环境可能遇到的问题，比如图片服务器的自定义域名、使用S3 低冗余储存类型来降低成本等。
其实实际生产环境还可以对成本进行进一步优化，比如最简单的使用S3储存桶的生命周期功能，把一定时间以外的图片缩率图自动删除。
当然也可以分析S3 访问日志，找出访问记录比较久远的对象 、调用S3 API 对长久未使用的缩率图进行定时清理，甚至可以根据日志做分析 找出热门的图片格式需求，提前针对热门图片生产缩率图，提高用户体验。
另外通过以上的架构描述可以看出，由于AWS 所提供的所有云服务都有丰富的API 接口 和详细的配置选项，它就像一个乐高玩具一般，开发人员或者架构师可以脑洞大开随意去组合它成为自己想要的产品。

