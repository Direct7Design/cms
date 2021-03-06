<?php
/**
 * Post class file.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package cms
 */

/**
 * The post class.
 *
 * @package cms
 */
class post 
{

    /**
     * Get the latest posts from the database.
     *
     * @param integer $limit The number of posts to get. Defaults to 10.
     *
     * @return array Returns an array of the last number of posts.
     */
    public static function getPosts($limit=10)
    {
        $sql  = "SELECT p.postid, p.subject, p.content, p.postdate, p.modifieddate, u.username AS postbyuser";
        $sql .= " FROM ".db::getPrefix()."posts p INNER JOIN ".db::getPrefix()."users u";
        $sql .= " ON (p.postby=u.userid)";
        $sql .= " ORDER BY postdate DESC LIMIT ".$limit;

        $query   = db::select($sql);
        $results = db::fetch($query);

        return $results;
    }

    /**
     * Get post content based on the date and subject passed in.
     *
     * This will be used if we are displaying a particular blog post.
     * We need both the date and the subject in case there are multiple
     * posts per day.
     *
     * @param string $postdate    The date to get the post for.
     * @param string $postsubject The subject to get the post for.
     *
     * @return array
     */
    public static function getPostByDate($postdate, $postsubject)
    {
        $sql  = "SELECT p.postid, p.subject, p.content, p.postdate, p.modifieddate, u.username AS postbyuser";
        $sql .= " FROM ".db::getPrefix()."posts p INNER JOIN ".db::getPrefix()."users u";
        $sql .= " ON (p.postby=u.userid)";
        $sql .= " WHERE DATE(p.postdate) = :postdate AND p.subject=:postsubject";

        $query  = db::select($sql, array($postdate, urldecode($postsubject)));
        $result = db::fetch($query);

        return $result;
    }

    /**
     * Get the next and previous post based on the post id passed in.
     *
     * Returns an array of information about the next and previous:
     * - postid
     * - subject
     * - date
     * - whether it's previous or next
     *
     * @param integer $postid The post to get next/previous for.
     *
     * @return array
     */
    public static function getNextAndPrevPost($postid)
    {
        $sql     = "(";
        $sql    .= " SELECT p.postid, p.subject, p.postdate, 'previous' AS pos";
        $sql    .= " FROM ".db::getPrefix()."posts p";
        $sql    .= " WHERE p.postid < :postprev";
        $sql    .= " ORDER BY postid DESC LIMIT 1";
        $sql    .= ")";
        $sql    .= " UNION ALL ";
        $sql    .= "(";
        $sql    .= " SELECT p.postid, p.subject, p.postdate, 'next' AS pos";
        $sql    .= " FROM ".db::getPrefix()."posts p";
        $sql    .= " WHERE p.postid > :postnext";
        $sql    .= " ORDER BY postid ASC LIMIT 1";
        $sql    .= ")";
        $query   = db::select($sql, array($postid, $postid));
        $results = db::fetchAll($query);

        return $results;
    }

    /**
     * Return a safe url based on the post date and subject.
     *
     * @param string $postdate    The date of the post.
     * @param string $postsubject The subject of the post.
     *
     * @return string
     */
    public static function safeUrl($postdate, $postsubject)
    {
        if (is_numeric($postdate) === FALSE) {
            $postdate = strtotime($postdate);
        }
        $url = date('Y-m-d', $postdate).'/'.urlencode($postsubject);
        return $url;
    }

    /**
     * Change a postgres timestamp into a nice date.
     *
     * @param string $datetime The timestamp to transform.
     */
    public static function niceDate($datetime)
    {
        $time = strtotime($datetime);
        $date = date('jS M, Y', $time);
        return $date;
    }

    /**
     * Process an action for the frontend.
     *
     * @param string $action The action to process.
     *
     * @return void
     */
    public static function process($action='')
    {

        switch ($action)
        {
            case '':
                Post::showLatestPost();
            break;

            default:
                if (strpos($action, '/') === FALSE) {
                    Post::showLatestPost();
                } else {
                    list($date, $subject) = explode('/', $action);
                    $post = Post::getPostByDate($date, $subject);
                    if (empty($post) === FALSE) {
                        Post::showPost($post);
                    } else {
                        template::serveTemplate('post.invalid');
                    }
                }
        }
    }

    /**
     * Show the latest post.
     *
     * Works out the latest and passes it off to the showPost function.
     *
     * @return void
     */
    public static function showLatestPost()
    {
        $post = Post::getPosts(1);
        Post::showPost($post);
    }

    /**
     * Show a particular post.
     * 
     * Also works out the next and previous urls to show for the side nav bars.
     *
     * @param array $post The post info to show.
     *
     * @return void
     */
    public static function showPost($post=array())
    {
        if (empty($post) === TRUE) {
            template::serveTemplate('post.empty');
            return;
        }

        $post['gallery']  = post::getGallery($post);
        $post['postdate'] = post::niceDate($post['postdate']);
        $keywords = array(
            'content',
            'postbyuser',
            'postdate',
            'subject',
            'gallery',
        );
        foreach ($keywords as $keyword) {
            template::setKeyword('post.show', $keyword, $post[$keyword]);
        }
        template::setKeyword('header', 'pagetitle', ' - '.$post['subject']);

        $nextpost = '';
        $prevpost = '';

        $nextandprev = Post::getNextAndPrevPost($post['postid']);
        foreach ($nextandprev as $otherPost) {
            $url = Post::safeUrl($otherPost['postdate'], $otherPost['subject']);
            if ($otherPost['pos'] === 'previous') {
                $prevpost = $url;
            } else {
                $nextpost = $url;
            }
        }

        template::setKeyword('post.next',     'nextpost',     $nextpost);
        template::setKeyword('post.previous', 'previouspost', $prevpost);

        template::serveTemplate('post.show');
    }

    public static function getGallery($post=array())
    {

        if (empty($post) === TRUE) {
            return '';
        }

        $dataDir = config::get('datadir');

        $path = $dataDir.'/post/'.$post['postid'];

        if (is_dir($path) === FALSE) {
            return '';
        }

        $files = glob($path.'/*.jpg');
        if (empty($files) === TRUE) {
            return '';
        }

        natsort($files);

        $images = array();
        $thumbs = array();
        foreach ($files as $file) {
            $info   = getimagesize($file);
            $width  = $info[0];
            $height = $info[1];

            $url      = str_replace($dataDir, url::getUrl().'/data', $file);
            $images[] = array(
                'url'    => $url,
                'width'  => $width,
                'height' => $height,
            );

            $file   = $path.'/thumbs/'.basename($file);
            $info   = getimagesize($file);
            $width  = $info[0];
            $height = $info[1];

            $url      = str_replace($dataDir, url::getUrl().'/data', $file);
            $thumbs[] = array(
                'url'    => $url,
                'width'  => $width,
                'height' => $height,
            );
        }

        $code = '
                <div id="gallery">
                   <div id="slides">
                       <div class="slides_container">
        ';

        foreach ($images as $image) {
            $code .= '<div>';
            $code .= '<img src="'.$image['url'].'" width="'.$image['width'].'" height="'.$image['height'].'" />';
            $code .= '</div>';
        }

        $code .= '     </div><!-- end slides_container //-->
                       <div id="slides_nav">
                            <a href="#" class="slide-prev"><img src="~url::baseurl~/web/images/prev.png" border="0" /></a>
                            <ul class="pagination">
        ';

        foreach ($thumbs as $thumb) {
            $code .= '<li>';
            $code .= '<a href="#">';
            $code .= '<img src="'.$thumb['url'].'" width="'.$thumb['width'].'" height="'.$height.'" />';
            $code .= '</a>';
            $code .= '</li>';
        }
        $code .= '
                            </ul>
                            <a href="#" class="slide-next"><img src="~url::baseurl~/web/images/next.png" border="0" /></a>
                       </div>
                    </div><!--end slides //-->
                </div><!-- end gallery//-->
        ';

        return $code;
    }

}

/* vim: set expandtab ts=4 sw=4: */
